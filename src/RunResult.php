<?php

namespace Bembe\AiBudget;

/**
 * The outcome of AiRunner::run(): the content, the model that actually served
 * it (which may be the fallback), tokens and latency aggregated across every
 * call the run made — including the schema re-ask — and the real cost.
 */
final class RunResult
{
    /**
     * @param  array<string, mixed>|null  $json  decoded output when the profile declares a json_schema
     */
    public function __construct(
        public readonly string $content,
        public readonly string $model,
        public readonly bool $usedFallback,
        public readonly int $inputTokens,
        public readonly int $outputTokens,
        public readonly int $latencyMs,
        public readonly float $costUsd,
        public readonly float $costLocal,
        public readonly ?array $json = null,
    ) {}
}
