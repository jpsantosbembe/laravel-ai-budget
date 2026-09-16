# Laravel AI Budget

[![tests](https://github.com/jpsantosbembe/laravel-ai-budget/actions/workflows/tests.yml/badge.svg)](https://github.com/jpsantosbembe/laravel-ai-budget/actions/workflows/tests.yml)
[![static analysis](https://github.com/jpsantosbembe/laravel-ai-budget/actions/workflows/static.yml/badge.svg)](https://github.com/jpsantosbembe/laravel-ai-budget/actions/workflows/static.yml)
[![license](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE.md)

Cost-governed LLM execution for Laravel.

Most LLM packages are transport wrappers: they hand you a client and leave the
bill to you. This one starts from the other end. You describe a task once as a
**profile** — model, fallback, limits, output schema, retries and a **spend
ceiling** — and every run through that profile is checked against the ceiling
*before* a single token is spent, validated on the way out, and written to a
usage log with its real cost.

It was extracted from a production application where it has been running since
April 2026.

## What it actually does

| | |
|---|---|
| **Spend ceiling** | The estimated cost of a run is computed from the price table and compared against the profile's `max_cost_per_run`. Over the line, `BudgetExceededException` is thrown before the provider is called. |
| **Retries and fallback** | `retries` attempts per model; once they are exhausted the profile's `fallback_model` takes over — and the result tells you whether it did. |
| **Schema validation** | When a profile declares a `json_schema`, the output is decoded (markdown fences and all), validated, and re-asked **once** if it does not match. |
| **Honest accounting** | Every call lands in `ai_usage_logs` with tokens, latency, and cost in USD and in your local currency — including the calls that failed validation, because the provider billed for those too. |
| **Pipelines** | Multi-stage `map`/`single` workflows over job batches, with the ceiling applied to the whole pipeline, per-item failure isolation and retry of only what failed. |

## Installation

```bash
composer require jpsantosbembe/laravel-ai-budget
php artisan migrate
```

Publish the configuration when you want to own the price table or point at a
live FX rate:

```bash
php artisan vendor:publish --tag=ai-budget-config
```

## Getting started

Store a credential and define a profile:

```php
use Bembe\AiBudget\Models\AiCredential;
use Bembe\AiBudget\Models\AiProfile;

AiCredential::create([
    'key' => 'openrouter',
    'provider' => 'openrouter',
    'credentials' => ['api_key' => config('services.openrouter.key')],
]);

AiProfile::create([
    'key' => 'support_triage',
    'model' => 'openai/gpt-4o-mini',
    'fallback_model' => 'google/gemini-2.5-flash',
    'retries' => 2,
    'max_output_tokens' => 500,
    'max_cost_per_run' => 0.05,
    'system_prompt' => 'You triage customer support messages.',
    'json_schema' => [
        'type' => 'object',
        'required' => ['urgency', 'topic'],
        'properties' => [
            'urgency' => ['type' => 'string', 'enum' => ['low', 'high']],
            'topic' => ['type' => 'string'],
        ],
    ],
    'settings' => ['credential_key' => 'openrouter'],
]);
```

Run it:

```php
use Bembe\AiBudget\AiRunner;

$result = app(AiRunner::class)->run('support_triage', [
    ['role' => 'user', 'content' => $message->body],
], [
    'subject_type' => $message::class,
    'subject_id' => $message->id,
]);

$result->json;         // ['urgency' => 'high', 'topic' => 'billing']
$result->model;        // which model actually answered
$result->usedFallback; // whether the primary model gave up
$result->costLocal;    // what it really cost
```

Ask what it would cost before committing to it:

```php
app(AiRunner::class)->estimate('support_triage', approximateInputTokens: 1_200);
// ['tokens' => 1700, 'usd' => 0.00048, 'local' => 0.0024, 'currency' => 'BRL']
```

## Currency

Provider prices are quoted in USD; budgets are usually not. Set your currency
and how to convert:

```php
// config/ai-budget.php
'currency' => 'BRL',
'fx_rate' => fn () => Cache::remember('usd-brl', 3600, fn () => Fx::usdTo('BRL')),
```

`max_cost_per_run` and `cost_local` are then expressed in that currency, and
`cost_usd` is always kept alongside it.

## Pipelines

A pipeline is an ordered list of stages. `map` stages fan out — one queued job
per item — and `single` stages run once, typically to synthesise what the
previous stages produced.

```php
class WeeklyReview extends AiPipeline
{
    public function key(): string { return 'weekly_review'; }
    public function profileKey(): string { return 'review_master'; }

    public function stages(): array
    {
        return [
            ['name' => 'analyse', 'type' => self::STAGE_MAP],
            ['name' => 'summarise', 'type' => self::STAGE_SINGLE],
        ];
    }

    public function estimateStage(string $stage, array $params): float { /* ... */ }

    public function items(AiPipelineRun $run, string $stage): array
    {
        return Conversation::whereWeek('created_at', $run->params['week'])->pluck('id')->map(strval(...))->all();
    }

    public function handleItem(AiPipelineRun $run, string $stage, string $itemKey): array
    {
        $result = app(AiRunner::class)->run('conversation_analysis', [/* ... */]);

        return ['payload' => $result->json, 'cost' => $result->costLocal];
    }
}
```

```php
$run = app(AiPipelineRunner::class)->dispatch(new WeeklyReview, ['week' => 38]);
app(AiPipelineRunner::class)->progressSnapshot($run);
app(AiPipelineRunner::class)->retry($run);  // only the items that failed
```

Progress is reported through the `PipelineProgress` event. It is a plain event,
not a broadcast one: the durable state lives in the database and
`progressSnapshot()` rebuilds the identical payload over HTTP, so a websocket
is an optimisation rather than a requirement. Pipelines need Laravel's
`job_batches` table (`php artisan queue:batches-table`).

## Providers

`OpenRouterTransport` ships with the package. Add your own by implementing
`ChatTransport` and registering it:

```php
// config/ai-budget.php
'transports' => [
    'openrouter' => Bembe\AiBudget\Transport\OpenRouterTransport::class,
    'anthropic'  => App\Ai\AnthropicTransport::class,
],
```

The transport is selected per credential through its `provider` column, so
different profiles can run against different providers in the same application.

An implementation must translate provider failures into the package's
exceptions — `InvalidCredentialException` for a rejected key, and
`RateLimitedException` when throttled — so retries and fallback behave the same
everywhere.

## Schema validation

The bundled validator supports the subset of JSON Schema that structured LLM
output actually uses: `type`, `required`, `properties`, `items` and `enum`,
recursively. That keeps the package dependency-free. If you need the full spec,
implement `SchemaValidator` around a real validator and point
`ai-budget.schema_validator` at it.

## Behaviour worth knowing

**A rejected API key disables the credential.** A 401 means every later run
would fail the same way, so the credential is switched off and the exception
propagates once, instead of silently burning through your queue.

**A failed re-ask is still recorded.** If the output fails the schema twice, the
tokens from *both* calls are written to the usage log before
`SchemaMismatchException` is thrown. A run that vanished from the log would be
exactly the run you most need to see: the model returning garbage while the bill
climbs.

**Unknown models are expensive by default.** A model missing from the price
table falls back to the configured generic price rather than zero, because a
zero would quietly disable the ceiling for the models nobody registered.

## Requirements

PHP 8.3+ and Laravel 12, on PostgreSQL, MySQL or SQLite.

Laravel 11 is deliberately not supported: as of this release every 11.x
release carries an unresolved security advisory, so Composer refuses to
install it under its default policy. Supporting a line you cannot install
cleanly is a promise the package cannot keep.

## Testing

```bash
composer install
vendor/bin/phpunit
```

## License

MIT. See [LICENSE.md](LICENSE.md).
