<?php

namespace Bembe\AiBudget\Jobs;

use Bembe\AiBudget\Models\AiPipelineItem;
use Bembe\AiBudget\Models\AiPipelineRun;
use Bembe\AiBudget\Pipelines\AiPipeline;
use Bembe\AiBudget\Pipelines\AiPipelineRegistry;
use Bembe\AiBudget\Pipelines\AiPipelineRunner;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;

/**
 * Processes one item of a pipeline stage. A failing item does NOT take the
 * batch down: it is marked `failed` with its error, the batch carries on, the
 * next stage decides what to do with what it got, and retry() reprocesses only
 * what failed.
 */
class RunPipelineStageItemJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    /**
     * In-job attempts for `map` items: one automatic retry before marking the
     * item failed, so a transient model hiccup does not cost the whole item.
     * `single` stages get one attempt and are resumed by the runner's retry().
     */
    public const MAP_ITEM_ATTEMPTS = 2;

    public function __construct(
        public int $runId,
        public string $stage,
        public string $itemKey,
    ) {}

    public function handle(AiPipelineRegistry $registry, AiPipelineRunner $runner, LoggerInterface $logger): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $run = AiPipelineRun::find($this->runId);

        if ($run === null || $run->status === AiPipelineRun::STATUS_CANCELLED) {
            return;
        }

        $item = AiPipelineItem::query()
            ->where('run_id', $this->runId)
            ->where('stage', $this->stage)
            ->where('item_key', $this->itemKey)
            ->first();

        if ($item === null || $item->status === AiPipelineItem::STATUS_DONE) {
            return;
        }

        $item->update(['status' => AiPipelineItem::STATUS_RUNNING, 'error' => null]);

        $pipeline = $registry->resolve($run->pipeline_key);
        $maxAttempts = $pipeline->stageType($this->stage) === AiPipeline::STAGE_MAP
            ? self::MAP_ITEM_ATTEMPTS
            : 1;

        $lastException = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $result = $pipeline->runStageItem($run, $this->stage, $this->itemKey);

                $cost = round($result['cost'], 4);

                $item->update([
                    'status' => AiPipelineItem::STATUS_DONE,
                    'payload' => $result['payload'],
                    'cost' => $cost,
                ]);

                if ($cost > 0) {
                    // Atomic increment: N workers finish items concurrently.
                    AiPipelineRun::whereKey($run->id)->increment('actual_cost', $cost);
                }

                $lastException = null;

                break;
            } catch (\Throwable $e) {
                $lastException = $e;

                $logger->warning('[ai-budget] Pipeline item attempt failed', [
                    'run_id' => $this->runId,
                    'stage' => $this->stage,
                    'item_key' => $this->itemKey,
                    'attempt' => $attempt,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($lastException !== null) {
            $item->update([
                'status' => AiPipelineItem::STATUS_FAILED,
                'error' => Str::limit($lastException->getMessage(), 500),
            ]);

            $logger->warning('[ai-budget] Pipeline item failed', [
                'run_id' => $this->runId,
                'stage' => $this->stage,
                'item_key' => $this->itemKey,
                'error' => $lastException->getMessage(),
            ]);
        }

        $runner->emitProgress($run->refresh());
    }
}
