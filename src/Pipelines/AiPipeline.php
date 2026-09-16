<?php

namespace Bembe\AiBudget\Pipelines;

use Bembe\AiBudget\Models\AiPipelineItem;
use Bembe\AiBudget\Models\AiPipelineRun;

/**
 * A multi-stage AI pipeline. Stages run in order; each one is either `map`
 * (fan-out: items() lists the keys, handleItem() processes one per job) or
 * `single` (handle() runs once — typically a synthesis over what the previous
 * stages produced, read back from $run->items()).
 *
 * Always execute through AiPipelineRunner (estimate/dispatch/retry); a pipeline
 * never enqueues itself. Model calls inside handleItem()/handle() go through
 * AiRunner, so they inherit the per-profile ceiling and cost accounting.
 */
abstract class AiPipeline
{
    public const STAGE_MAP = 'map';

    public const STAGE_SINGLE = 'single';

    /**
     * Stable identifier used in ai_pipeline_runs.pipeline_key and in the
     * registry.
     */
    abstract public function key(): string;

    /**
     * Key of the master profile — the one whose max_cost_per_run the dispatch
     * guard compares against the estimate for the WHOLE pipeline.
     */
    abstract public function profileKey(): string;

    /**
     * Stages, in execution order.
     *
     * @return list<array{name: string, type: 'map'|'single'}>
     */
    abstract public function stages(): array;

    /**
     * Estimated cost of ONE stage for the given params — sum AiRunner::estimate()
     * over the items you expect to process.
     *
     * @param  array<string, mixed>  $params
     */
    abstract public function estimateStage(string $stage, array $params): float;

    /**
     * Item keys of a `map` stage.
     *
     * @return list<string>
     */
    public function items(AiPipelineRun $run, string $stage): array
    {
        return [];
    }

    /**
     * Process one item of a `map` stage.
     *
     * @return array{payload: array<string, mixed>|null, cost: float}
     */
    public function handleItem(AiPipelineRun $run, string $stage, string $itemKey): array
    {
        throw new \LogicException("Pipeline '{$this->key()}' does not implement handleItem() for stage '{$stage}'.");
    }

    /**
     * Process a `single` stage.
     *
     * @return array{payload: array<string, mixed>|null, cost: float}
     */
    public function handle(AiPipelineRun $run, string $stage): array
    {
        throw new \LogicException("Pipeline '{$this->key()}' does not implement handle() for stage '{$stage}'.");
    }

    /**
     * Unified entry point used by the job: resolves the stage type and delegates.
     *
     * @return array{payload: array<string, mixed>|null, cost: float}
     */
    public function runStageItem(AiPipelineRun $run, string $stage, string $itemKey): array
    {
        if ($this->stageType($stage) === self::STAGE_SINGLE) {
            return $this->handle($run, $stage);
        }

        return $this->handleItem($run, $stage, $itemKey);
    }

    /**
     * Keys the stage will materialise into ai_pipeline_items.
     *
     * @return list<string>
     */
    public function itemKeysForStage(AiPipelineRun $run, string $stage): array
    {
        if ($this->stageType($stage) === self::STAGE_SINGLE) {
            return [AiPipelineItem::SINGLE_KEY];
        }

        return array_values(array_unique($this->items($run, $stage)));
    }

    /**
     * Human-readable stage label for progress UIs. Defaults to the technical
     * name; override with something like "Analysing conversation 12 of 32".
     */
    public function stageLabel(string $stage, int $done, int $total): string
    {
        return $stage;
    }

    /**
     * Extra progress metrics for the snapshot.
     *
     * @return array<string, int>
     */
    public function progressMeta(AiPipelineRun $run): array
    {
        return [];
    }

    /**
     * Called by the runner when the run ends (done or failed) — the place to
     * sync domain entities tied to the run.
     */
    public function finished(AiPipelineRun $run): void {}

    public function stageType(string $stage): string
    {
        foreach ($this->stages() as $definition) {
            if ($definition['name'] === $stage) {
                return $definition['type'];
            }
        }

        throw new \LogicException("Stage '{$stage}' does not exist in pipeline '{$this->key()}'.");
    }

    public function stageIndex(string $stage): ?int
    {
        foreach ($this->stages() as $index => $definition) {
            if ($definition['name'] === $stage) {
                return $index;
            }
        }

        return null;
    }
}
