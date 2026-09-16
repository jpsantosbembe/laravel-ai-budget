<?php

namespace Bembe\AiBudget\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired on every item completion and every stage change.
 *
 * A plain event on purpose: broadcasting is the application's choice. The
 * durable state lives in ai_pipeline_runs / ai_pipeline_items, and
 * AiPipelineRunner::progressSnapshot() rebuilds the exact same payload over
 * HTTP, so a websocket is an optimisation and never a requirement.
 */
class PipelineProgress
{
    use Dispatchable;

    /**
     * @param  array<string, int>  $meta
     */
    public function __construct(
        public readonly int $runId,
        public readonly string $pipelineKey,
        public readonly ?int $userId,
        public readonly string $stage,
        public readonly string $stageLabel,
        public readonly int $done,
        public readonly int $total,
        public readonly int $failed,
        public readonly float $actualCost,
        public readonly int $elapsedSeconds,
        public readonly string $status,
        public readonly array $meta = [],
    ) {}
}
