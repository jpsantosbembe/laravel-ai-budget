<?php

namespace Bembe\AiBudget;

use Bembe\AiBudget\Exceptions\BudgetExceededException;
use Bembe\AiBudget\Exceptions\InvalidCredentialException;
use Bembe\AiBudget\Exceptions\SchemaMismatchException;
use Bembe\AiBudget\Models\AiCredential;
use Bembe\AiBudget\Models\AiProfile;
use Bembe\AiBudget\Models\AiUsageLog;
use Bembe\AiBudget\Schema\SchemaValidator;
use Bembe\AiBudget\Support\CostCalculator;
use Bembe\AiBudget\Support\JsonExtractor;
use Bembe\AiBudget\Transport\ChatTransport;
use Bembe\AiBudget\Transport\TransportFactory;
use Illuminate\Contracts\Config\Repository as Config;
use Psr\Log\LoggerInterface;

/**
 * Runs an AI profile and enforces its guarantees:
 *
 * - spend ceiling checked BEFORE the call (max_cost_per_run -> BudgetExceededException)
 * - retries per model
 * - fallback model once the retries are exhausted
 * - output validated against the profile's json_schema, with a single re-ask
 * - real cost (tokens x price table x fx rate) written to ai_usage_logs
 */
class AiRunner
{
    public function __construct(
        private readonly TransportFactory $transports,
        private readonly CostCalculator $cost,
        private readonly SchemaValidator $validator,
        private readonly LoggerInterface $logger,
        private readonly Config $config,
    ) {}

    /**
     * Execute a profile.
     *
     * $modelOverride swaps the PRIMARY model for this run only; the profile's
     * fallback_model still applies.
     *
     * @param  array<int, array{role: string, content: string}>  $messages
     * @param  array{subject_type?: string|null, subject_id?: int|string|null, context?: array<string, mixed>|null}  $attribution
     *
     * @throws BudgetExceededException when the estimate exceeds the profile's ceiling
     * @throws InvalidCredentialException when the provider rejects the key (credential auto-disabled)
     * @throws SchemaMismatchException when the output fails the schema twice
     * @throws \RuntimeException when the profile or credential is not usable, or every attempt failed
     */
    public function run(string $profileKey, array $messages, array $attribution = [], ?string $modelOverride = null): RunResult
    {
        $profile = $this->resolveProfile($profileKey);
        $credential = $this->resolveCredential($profile);
        $transport = $this->transports->for($credential);

        $this->assertWithinBudget($profile, $messages, $modelOverride);

        $models = array_values(array_unique(array_filter([
            $modelOverride ?: $profile->model,
            $profile->fallback_model,
        ])));

        $attemptsPerModel = max(1, (int) $profile->retries);
        $lastException = null;

        foreach ($models as $modelIndex => $model) {
            for ($attempt = 1; $attempt <= $attemptsPerModel; $attempt++) {
                try {
                    return $this->executeAttempt(
                        profile: $profile,
                        transport: $transport,
                        model: $model,
                        messages: $messages,
                        attribution: $attribution,
                        usedFallback: $modelIndex > 0,
                    );
                } catch (InvalidCredentialException $e) {
                    $credential->update(['enabled' => false]);

                    $this->logger->critical("[ai-budget] Credential '{$credential->key}' disabled: the provider rejected the API key.", [
                        'profile' => $profile->key,
                    ]);

                    throw $e;
                } catch (SchemaMismatchException $e) {
                    // The output was wrong, not the call. Retrying the same
                    // model rarely helps and the re-ask already doubled the
                    // bill, so fall through to the next model instead.
                    $lastException = $e;

                    break;
                } catch (\Throwable $e) {
                    $lastException = $e;

                    $this->logger->warning('[ai-budget] Attempt failed', [
                        'profile' => $profile->key,
                        'model' => $model,
                        'attempt' => $attempt,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        throw $lastException ?? new \RuntimeException("Profile '{$profileKey}' has no runnable model.");
    }

    /**
     * Estimate a run before making it — for a confirmation screen, or to decide
     * whether to bother.
     *
     * @return array{tokens: int, usd: float, local: float, currency: string}
     */
    public function estimate(string $profileKey, int $approximateInputTokens, ?string $modelOverride = null): array
    {
        return $this->estimateForProfile($this->resolveProfile($profileKey), $approximateInputTokens, $modelOverride);
    }

    private function resolveProfile(string $profileKey): AiProfile
    {
        $profile = AiProfile::query()->where('key', $profileKey)->first();

        if ($profile === null || ! $profile->isRunnable()) {
            throw new \RuntimeException("AI profile '{$profileKey}' does not exist or is disabled.");
        }

        return $profile;
    }

    private function resolveCredential(AiProfile $profile): AiCredential
    {
        $credentialKey = $profile->credentialKey();

        $credential = AiCredential::query()->where('key', $credentialKey)->first();

        if ($credential === null || ! $credential->isUsable()) {
            throw new \RuntimeException("AI credential '{$credentialKey}' (profile '{$profile->key}') is not configured.");
        }

        return $credential;
    }

    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     */
    private function assertWithinBudget(AiProfile $profile, array $messages, ?string $modelOverride = null): void
    {
        if ($profile->max_cost_per_run === null) {
            return;
        }

        $estimate = $this->estimateForProfile(
            $profile,
            $this->approximateInputTokens($profile, $messages),
            $modelOverride,
        );

        if ($estimate['local'] > (float) $profile->max_cost_per_run) {
            throw new BudgetExceededException(sprintf(
                "Run of profile '%s' blocked: estimated %s %.4f exceeds the ceiling of %.2f per run.",
                $profile->key,
                $estimate['currency'],
                $estimate['local'],
                (float) $profile->max_cost_per_run,
            ));
        }
    }

    /**
     * @return array{tokens: int, usd: float, local: float, currency: string}
     */
    private function estimateForProfile(AiProfile $profile, int $inputTokens, ?string $modelOverride = null): array
    {
        $outputTokens = (int) $profile->max_output_tokens;
        $usd = $this->cost->costUsd($modelOverride ?: $profile->model, $inputTokens, $outputTokens);

        return [
            'tokens' => $inputTokens + $outputTokens,
            'usd' => round($usd, 6),
            'local' => round($this->cost->toLocal($usd), 6),
            'currency' => $this->cost->currency(),
        ];
    }

    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     */
    private function approximateInputTokens(AiProfile $profile, array $messages): int
    {
        $text = (string) $profile->system_prompt;

        foreach ($messages as $message) {
            $text .= $message['content'];
        }

        return $this->cost->approximateTokens($text);
    }

    /**
     * One full attempt: the call, plus — when the profile declares a schema —
     * validation with a single re-ask. Tokens from both calls are folded into
     * one usage row, because the provider billed for both.
     *
     * @param  array<int, array{role: string, content: string}>  $messages
     * @param  array<string, mixed>  $attribution
     */
    private function executeAttempt(
        AiProfile $profile,
        ChatTransport $transport,
        string $model,
        array $messages,
        array $attribution,
        bool $usedFallback,
    ): RunResult {
        $systemPrompt = (string) ($profile->system_prompt ?? '');
        $maxTokens = max(1, (int) $profile->max_output_tokens);
        $temperature = $profile->temperature !== null ? (float) $profile->temperature : null;
        $timeout = $profile->timeoutSeconds((int) $this->config->get('ai-budget.timeout', 30));

        $result = $transport->chat($systemPrompt, $messages, $model, $maxTokens, $temperature, $timeout);

        $inputTokens = $result->inputTokens;
        $outputTokens = $result->outputTokens;
        $latencyMs = $result->latencyMs;

        $json = null;
        $schema = $profile->json_schema;

        if (is_array($schema) && $schema !== []) {
            $json = $this->decodeAndValidate($result->content, $schema);

            if ($json === null) {
                $retryMessages = [
                    ...$messages,
                    ['role' => 'assistant', 'content' => $result->content],
                    ['role' => 'user', 'content' => 'Your previous answer did not match the required format. Reply with valid JSON only, conforming to this JSON Schema: '.json_encode($schema, JSON_UNESCAPED_UNICODE)],
                ];

                $result = $transport->chat($systemPrompt, $retryMessages, $model, $maxTokens, $temperature, $timeout);

                $inputTokens += $result->inputTokens;
                $outputTokens += $result->outputTokens;
                $latencyMs += $result->latencyMs;

                $json = $this->decodeAndValidate($result->content, $schema);

                if ($json === null) {
                    // Both calls were billed by the provider. Throwing without
                    // recording would make that spend vanish from the usage log
                    // and from the ceiling — precisely in the case where the
                    // model is returning garbage and the bill keeps climbing
                    // with nothing to show for it.
                    $this->recordUsage($profile, $model, $inputTokens, $outputTokens, $latencyMs, $attribution);

                    throw new SchemaMismatchException("Output from '{$model}' failed the json_schema of profile '{$profile->key}' after a re-ask.");
                }
            }
        }

        [$costUsd, $costLocal] = $this->recordUsage($profile, $model, $inputTokens, $outputTokens, $latencyMs, $attribution);

        return new RunResult(
            content: $result->content,
            model: $model,
            usedFallback: $usedFallback,
            inputTokens: $inputTokens,
            outputTokens: $outputTokens,
            latencyMs: $latencyMs,
            costUsd: round($costUsd, 6),
            costLocal: round($costLocal, 6),
            json: $json,
        );
    }

    /**
     * Records usage and returns the cost. A single place, because it has to
     * happen on success AND on failure after the re-ask.
     *
     * @param  array<string, mixed>  $attribution
     * @return array{0: float, 1: float}
     */
    private function recordUsage(
        AiProfile $profile,
        string $model,
        int $inputTokens,
        int $outputTokens,
        int $latencyMs,
        array $attribution,
    ): array {
        $costUsd = $this->cost->costUsd($model, $inputTokens, $outputTokens);
        $costLocal = $this->cost->toLocal($costUsd);

        AiUsageLog::create([
            'profile_key' => $profile->key,
            'subject_type' => $attribution['subject_type'] ?? null,
            'subject_id' => $attribution['subject_id'] ?? null,
            'model' => $model,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'latency_ms' => $latencyMs,
            'cost_usd' => round($costUsd, 6),
            'cost_local' => round($costLocal, 6),
            'context' => $attribution['context'] ?? null,
            'created_at' => now(),
        ]);

        return [$costUsd, $costLocal];
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>|null
     */
    private function decodeAndValidate(string $content, array $schema): ?array
    {
        $decoded = JsonExtractor::decode($content);

        if ($decoded === null) {
            return null;
        }

        return $this->validator->matches($decoded, $schema) ? $decoded : null;
    }
}
