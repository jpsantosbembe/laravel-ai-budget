<?php

namespace Bembe\AiBudget\Pipelines;

use Bembe\AiBudget\Events\PipelineProgress;
use Bembe\AiBudget\Exceptions\BudgetExceededException;
use Bembe\AiBudget\Jobs\RunPipelineStageItemJob;
use Bembe\AiBudget\Models\AiPipelineItem;
use Bembe\AiBudget\Models\AiPipelineRun;
use Bembe\AiBudget\Models\AiProfile;
use Illuminate\Support\Facades\Bus;
use Psr\Log\LoggerInterface;

/**
 * Orchestrates an AiPipeline's stages over queued job batches:
 *
 * - estimate(): sums the per-stage estimates, for a UI and for the guard
 * - dispatch(): checks the total against the master profile's ceiling, creates
 *   the run and enqueues the first stage
 * - each stage is a Bus::batch of RunPipelineStageItemJob with allowFailures —
 *   a failed item stays `failed` while the batch carries on, and the next stage
 *   decides what to do with what it got
 * - when every item of a stage settles (the batch's finally callback) the next
 *   stage is enqueued; after the last one the run is finished
 * - retry(): re-enqueues only the failed/pending items of the current stage
 *
 * Durable state lives in the database; progress events are a notification, not
 * the source of truth. The accumulated cost is incremented atomically, which is
 * what makes it safe with N workers running in parallel.
 */
class AiPipelineRunner
{
    public function __construct(
        private readonly AiPipelineRegistry $registry,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Total estimated cost of the pipeline for the given params.
     *
     * @param  array<string, mixed>  $params
     */
    public function estimate(AiPipeline $pipeline, array $params): float
    {
        $total = 0.0;

        foreach ($pipeline->stages() as $stage) {
            $total += (float) $pipeline->estimateStage($stage['name'], $params);
        }

        return round($total, 4);
    }

    /**
     * Create the run and enqueue the first stage. Throws BudgetExceededException
     * if the estimate exceeds the master profile's ceiling.
     *
     * @param  array<string, mixed>  $params
     */
    public function dispatch(AiPipeline $pipeline, array $params, ?int $userId = null): AiPipelineRun
    {
        if (! $this->registry->has($pipeline->key())) {
            $this->registry->register($pipeline);
        }

        $estimated = $this->estimate($pipeline, $params);

        $this->assertWithinBudget($pipeline, $estimated);

        $run = AiPipelineRun::create([
            'pipeline_key' => $pipeline->key(),
            'status' => AiPipelineRun::STATUS_PENDING,
            'params' => $params,
            'created_by' => $userId,
            'estimated_cost' => $estimated,
        ]);

        $this->dispatchStage($run, 0);

        return $run->refresh();
    }

    /**
     * Re-enqueue only the failed/pending items of the current stage, so a
     * partially failed run resumes without reprocessing what already worked.
     */
    public function retry(AiPipelineRun $run): AiPipelineRun
    {
        if (! in_array($run->status, [AiPipelineRun::STATUS_FAILED, AiPipelineRun::STATUS_RUNNING], true)) {
            throw new \RuntimeException("Run #{$run->id} cannot be resumed from status '{$run->status}'.");
        }

        $pipeline = $this->registry->resolve($run->pipeline_key);
        $stageIndex = $run->current_stage !== null ? $pipeline->stageIndex($run->current_stage) : null;

        if ($stageIndex === null) {
            throw new \RuntimeException("Run #{$run->id} has no valid current stage to resume.");
        }

        $retryable = $run->items()
            ->where('stage', $run->current_stage)
            ->whereIn('status', [AiPipelineItem::STATUS_FAILED, AiPipelineItem::STATUS_PENDING])
            ->get();

        if ($retryable->isEmpty()) {
            throw new \RuntimeException("Run #{$run->id} has no failed/pending items in stage '{$run->current_stage}'.");
        }

        $run->items()
            ->whereIn('id', $retryable->pluck('id'))
            ->update(['status' => AiPipelineItem::STATUS_PENDING, 'error' => null]);

        $run->update([
            'status' => AiPipelineRun::STATUS_RUNNING,
            'error' => null,
            'finished_at' => null,
        ]);

        $this->dispatchStageBatch($run, $stageIndex, $retryable->pluck('item_key')->all());

        return $run->refresh();
    }

    public function cancel(AiPipelineRun $run): void
    {
        if ($run->isFinished()) {
            return;
        }

        $run->update([
            'status' => AiPipelineRun::STATUS_CANCELLED,
            'finished_at' => now(),
        ]);
    }

    /**
     * Enqueue a stage: materialise its items and dispatch the batch.
     */
    public function dispatchStage(AiPipelineRun $run, int $stageIndex): void
    {
        $pipeline = $this->registry->resolve($run->pipeline_key);
        $stages = $pipeline->stages();

        if (! isset($stages[$stageIndex])) {
            $this->finish($run);

            return;
        }

        $stageName = $stages[$stageIndex]['name'];

        $run->update([
            'status' => AiPipelineRun::STATUS_RUNNING,
            'current_stage' => $stageName,
            'started_at' => $run->started_at ?? now(),
        ]);

        foreach ($pipeline->itemKeysForStage($run, $stageName) as $itemKey) {
            AiPipelineItem::firstOrCreate(
                ['run_id' => $run->id, 'stage' => $stageName, 'item_key' => $itemKey],
                ['status' => AiPipelineItem::STATUS_PENDING],
            );
        }

        $pendingKeys = $run->items()
            ->where('stage', $stageName)
            ->whereIn('status', [AiPipelineItem::STATUS_PENDING, AiPipelineItem::STATUS_FAILED])
            ->pluck('item_key')
            ->all();

        $this->emitProgress($run->refresh());

        $this->dispatchStageBatch($run, $stageIndex, $pendingKeys);
    }

    /**
     * Batch completion callback: move to the next stage, unless the run was
     * cancelled meanwhile.
     */
    public function completeStage(int $runId, int $stageIndex): void
    {
        $run = AiPipelineRun::find($runId);

        if ($run === null || $run->status === AiPipelineRun::STATUS_CANCELLED) {
            return;
        }

        $this->dispatchStage($run, $stageIndex + 1);
    }

    /**
     * Progress of the current stage. The same payload feeds the event and any
     * HTTP polling endpoint, so both always agree.
     *
     * @return array{stage: string, stage_label: string, done: int, total: int, failed: int, actual_cost: float, elapsed_seconds: int, status: string, meta: array<string, int>}|null
     */
    public function progressSnapshot(AiPipelineRun $run): ?array
    {
        if ($run->current_stage === null) {
            return null;
        }

        // Plain aggregates rather than FILTER, which MySQL does not support.
        $counts = $run->items()
            ->where('stage', $run->current_stage)
            ->selectRaw('count(*) as total')
            ->selectRaw("sum(case when status in ('done', 'failed') then 1 else 0 end) as processed")
            ->selectRaw("sum(case when status = 'failed' then 1 else 0 end) as failed")
            ->first();

        $pipeline = $this->registry->has($run->pipeline_key)
            ? $this->registry->resolve($run->pipeline_key)
            : null;

        $done = (int) ($counts->processed ?? 0);
        $total = (int) ($counts->total ?? 0);

        return [
            'stage' => $run->current_stage,
            'stage_label' => $pipeline?->stageLabel($run->current_stage, $done, $total) ?? $run->current_stage,
            'done' => $done,
            'total' => $total,
            'failed' => (int) ($counts->failed ?? 0),
            'actual_cost' => (float) $run->actual_cost,
            'elapsed_seconds' => $run->started_at !== null ? max(0, (int) $run->started_at->diffInSeconds(now())) : 0,
            'status' => $run->status,
            'meta' => $pipeline?->progressMeta($run) ?? [],
        ];
    }

    public function emitProgress(AiPipelineRun $run): void
    {
        $snapshot = $this->progressSnapshot($run);

        if ($snapshot === null) {
            return;
        }

        event(new PipelineProgress(
            runId: (int) $run->id,
            pipelineKey: $run->pipeline_key,
            userId: $run->created_by !== null ? (int) $run->created_by : null,
            stage: $snapshot['stage'],
            stageLabel: $snapshot['stage_label'],
            done: $snapshot['done'],
            total: $snapshot['total'],
            failed: $snapshot['failed'],
            actualCost: $snapshot['actual_cost'],
            elapsedSeconds: $snapshot['elapsed_seconds'],
            status: $snapshot['status'],
            meta: $snapshot['meta'],
        ));
    }

    /**
     * @param  list<string>  $itemKeys
     */
    private function dispatchStageBatch(AiPipelineRun $run, int $stageIndex, array $itemKeys): void
    {
        $runId = (int) $run->id;
        $stageName = (string) $run->current_stage;

        if ($itemKeys === []) {
            $this->completeStage($runId, $stageIndex);

            return;
        }

        $jobs = array_map(
            fn (string $itemKey): RunPipelineStageItemJob => new RunPipelineStageItemJob($runId, $stageName, $itemKey),
            $itemKeys,
        );

        Bus::batch($jobs)
            ->allowFailures()
            ->name("ai-pipeline:{$run->pipeline_key}:{$stageName}")
            ->finally(function () use ($runId, $stageIndex): void {
                app(AiPipelineRunner::class)->completeStage($runId, $stageIndex);
            })
            ->dispatch();
    }

    /**
     * Finish the run after the last stage: `failed` when the final stage still
     * has failed items (that is what retry() resumes), otherwise `done`.
     */
    private function finish(AiPipelineRun $run): void
    {
        $failedInFinalStage = $run->current_stage === null ? 0 : $run->items()
            ->where('stage', $run->current_stage)
            ->where('status', AiPipelineItem::STATUS_FAILED)
            ->count();

        $run->update([
            'status' => $failedInFinalStage > 0 ? AiPipelineRun::STATUS_FAILED : AiPipelineRun::STATUS_DONE,
            'error' => $failedInFinalStage > 0
                ? "{$failedInFinalStage} item(s) failed in stage '{$run->current_stage}'."
                : null,
            'finished_at' => now(),
        ]);

        $this->logger->info('[ai-budget] Pipeline finished', [
            'run_id' => $run->id,
            'pipeline_key' => $run->pipeline_key,
            'status' => $run->status,
            'actual_cost' => $run->actual_cost,
        ]);

        $run->refresh();

        if ($this->registry->has($run->pipeline_key)) {
            $this->registry->resolve($run->pipeline_key)->finished($run);
        }

        $this->emitProgress($run);
    }

    private function assertWithinBudget(AiPipeline $pipeline, float $estimated): void
    {
        $profile = AiProfile::query()->where('key', $pipeline->profileKey())->first();

        if ($profile === null || $profile->max_cost_per_run === null) {
            return;
        }

        if ($estimated > (float) $profile->max_cost_per_run) {
            throw new BudgetExceededException(sprintf(
                "Pipeline '%s' blocked: estimated %.4f exceeds the ceiling of %.2f on profile '%s'.",
                $pipeline->key(),
                $estimated,
                (float) $profile->max_cost_per_run,
                $profile->key,
            ));
        }
    }
}
