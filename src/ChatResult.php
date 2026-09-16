<?php

namespace Bembe\AiBudget;

/**
 * One provider call: the content plus what it cost in tokens and latency.
 */
final class ChatResult
{
    public function __construct(
        public readonly string $content,
        public readonly int $inputTokens,
        public readonly int $outputTokens,
        public readonly int $latencyMs,
        public readonly bool $contentWasNull = false,
    ) {}
}
