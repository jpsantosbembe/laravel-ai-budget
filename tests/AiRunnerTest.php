<?php

namespace Bembe\AiBudget\Tests;

use Bembe\AiBudget\AiRunner;
use Bembe\AiBudget\Exceptions\BudgetExceededException;
use Bembe\AiBudget\Exceptions\InvalidCredentialException;
use Bembe\AiBudget\Exceptions\SchemaMismatchException;
use Bembe\AiBudget\Models\AiCredential;
use Bembe\AiBudget\Models\AiUsageLog;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;

class AiRunnerTest extends TestCase
{
    private function completion(string $content, int $inputTokens = 100, int $outputTokens = 50): array
    {
        return [
            'choices' => [['message' => ['content' => $content], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => $inputTokens, 'completion_tokens' => $outputTokens],
        ];
    }

    #[Test]
    public function it_runs_a_profile_and_records_the_real_cost(): void
    {
        Http::fake(['openrouter.ai/*' => Http::response($this->completion('hello there'))]);

        $this->credential();
        $this->profile(['model' => 'openai/gpt-4o-mini']);

        $result = app(AiRunner::class)->run('default', [['role' => 'user', 'content' => 'hi']]);

        $this->assertSame('hello there', $result->content);
        $this->assertSame('openai/gpt-4o-mini', $result->model);
        $this->assertFalse($result->usedFallback);

        // gpt-4o-mini: 0.15 USD/M in, 0.60 USD/M out.
        $expectedUsd = (100 / 1_000_000) * 0.15 + (50 / 1_000_000) * 0.60;

        $this->assertEqualsWithDelta($expectedUsd, $result->costUsd, 0.0000001);
        $this->assertEqualsWithDelta($expectedUsd * 5.0, $result->costLocal, 0.0000001);

        $log = AiUsageLog::sole();
        $this->assertSame('default', $log->profile_key);
        $this->assertSame(100, $log->input_tokens);
        $this->assertSame(50, $log->output_tokens);
    }

    #[Test]
    public function it_blocks_a_run_whose_estimate_exceeds_the_ceiling_before_calling_the_provider(): void
    {
        Http::fake();

        $this->credential();
        $this->profile([
            'model' => 'anthropic/claude-sonnet-4.5',
            'max_output_tokens' => 4000,
            'max_cost_per_run' => 0.01,
        ]);

        $this->expectException(BudgetExceededException::class);

        try {
            app(AiRunner::class)->run('default', [['role' => 'user', 'content' => str_repeat('a', 40_000)]]);
        } finally {
            Http::assertNothingSent();
            $this->assertSame(0, AiUsageLog::count());
        }
    }

    #[Test]
    public function it_allows_a_run_that_fits_under_the_ceiling(): void
    {
        Http::fake(['openrouter.ai/*' => Http::response($this->completion('ok'))]);

        $this->credential();
        $this->profile(['model' => 'openai/gpt-5-nano', 'max_output_tokens' => 100, 'max_cost_per_run' => 1.00]);

        $this->assertSame('ok', app(AiRunner::class)->run('default', [['role' => 'user', 'content' => 'hi']])->content);
    }

    #[Test]
    public function it_falls_back_to_the_secondary_model_after_the_retries_are_exhausted(): void
    {
        Http::fakeSequence('openrouter.ai/*')
            ->push('upstream exploded', 500)
            ->push('upstream exploded', 500)
            ->push($this->completion('rescued by the fallback'));

        $this->credential();
        $this->profile([
            'model' => 'openai/gpt-4o-mini',
            'fallback_model' => 'google/gemini-2.5-flash',
            'retries' => 2,
        ]);

        $result = app(AiRunner::class)->run('default', [['role' => 'user', 'content' => 'hi']]);

        $this->assertSame('rescued by the fallback', $result->content);
        $this->assertSame('google/gemini-2.5-flash', $result->model);
        $this->assertTrue($result->usedFallback);
    }

    #[Test]
    public function it_re_asks_once_when_the_output_does_not_match_the_schema(): void
    {
        Http::fakeSequence('openrouter.ai/*')
            ->push($this->completion('not json at all'))
            ->push($this->completion('{"sentiment":"positive","score":7}'));

        $this->credential();
        $this->profile([
            'json_schema' => [
                'type' => 'object',
                'required' => ['sentiment', 'score'],
                'properties' => [
                    'sentiment' => ['type' => 'string', 'enum' => ['positive', 'negative']],
                    'score' => ['type' => 'integer'],
                ],
            ],
        ]);

        $result = app(AiRunner::class)->run('default', [['role' => 'user', 'content' => 'rate this']]);

        $this->assertSame(['sentiment' => 'positive', 'score' => 7], $result->json);

        // Both calls are folded into a single usage row.
        $this->assertSame(1, AiUsageLog::count());
        $this->assertSame(200, AiUsageLog::sole()->input_tokens);
    }

    #[Test]
    public function it_accepts_json_wrapped_in_a_markdown_fence(): void
    {
        Http::fake(['openrouter.ai/*' => Http::response($this->completion("```json\n{\"ok\":true}\n```"))]);

        $this->credential();
        $this->profile(['json_schema' => ['type' => 'object', 'required' => ['ok']]]);

        $this->assertSame(['ok' => true], app(AiRunner::class)->run('default', [['role' => 'user', 'content' => 'hi']])->json);
    }

    #[Test]
    public function it_still_records_the_spend_when_the_re_ask_also_fails_the_schema(): void
    {
        Http::fake(['openrouter.ai/*' => Http::response($this->completion('garbage'))]);

        $this->credential();
        $this->profile([
            'json_schema' => ['type' => 'object', 'required' => ['sentiment']],
        ]);

        try {
            app(AiRunner::class)->run('default', [['role' => 'user', 'content' => 'rate this']]);
            $this->fail('Expected a SchemaMismatchException.');
        } catch (SchemaMismatchException) {
            // The provider billed for both calls, so both must be on the record:
            // this is the case where the model returns garbage and the bill
            // climbs with nothing to show for it.
            $log = AiUsageLog::sole();
            $this->assertSame(200, $log->input_tokens);
            $this->assertSame(100, $log->output_tokens);
            $this->assertGreaterThan(0, $log->cost_usd);
        }
    }

    #[Test]
    public function it_disables_the_credential_when_the_provider_rejects_the_key(): void
    {
        Http::fake(['openrouter.ai/*' => Http::response(['error' => 'unauthorized'], 401)]);

        $this->credential();
        $this->profile();

        $this->expectException(InvalidCredentialException::class);

        try {
            app(AiRunner::class)->run('default', [['role' => 'user', 'content' => 'hi']]);
        } finally {
            $this->assertFalse(AiCredential::sole()->enabled);
        }
    }

    #[Test]
    public function it_refuses_to_run_a_disabled_profile(): void
    {
        $this->credential();
        $this->profile(['enabled' => false]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('does not exist or is disabled');

        app(AiRunner::class)->run('default', [['role' => 'user', 'content' => 'hi']]);
    }

    #[Test]
    public function profiles_can_share_one_credential(): void
    {
        Http::fake(['openrouter.ai/*' => Http::response($this->completion('shared'))]);

        $this->credential(['key' => 'shared-key']);
        $this->profile(['key' => 'triage', 'settings' => ['credential_key' => 'shared-key']]);

        $this->assertSame('shared', app(AiRunner::class)->run('triage', [['role' => 'user', 'content' => 'hi']])->content);
    }

    #[Test]
    public function it_estimates_before_spending_anything(): void
    {
        Http::fake();

        $this->credential();
        $this->profile(['model' => 'openai/gpt-4o-mini', 'max_output_tokens' => 1000]);

        $estimate = app(AiRunner::class)->estimate('default', 1000);

        $this->assertSame(2000, $estimate['tokens']);
        $this->assertSame('BRL', $estimate['currency']);
        $this->assertEqualsWithDelta($estimate['usd'] * 5.0, $estimate['local'], 0.0000001);
        Http::assertNothingSent();
    }
}
