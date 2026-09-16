<?php

namespace Bembe\AiBudget\Tests\Fixtures;

use Bembe\AiBudget\Models\AiPipelineRun;
use Bembe\AiBudget\Pipelines\AiPipeline;

/**
 * A map stage that fans out over N items, followed by a single synthesis stage.
 */
class TwoStagePipeline extends AiPipeline
{
    public const PROFILE = 'pipeline-master';

    public function key(): string
    {
        return 'two-stage';
    }

    public function profileKey(): string
    {
        return self::PROFILE;
    }

    public function stages(): array
    {
        return [
            ['name' => 'analyse', 'type' => self::STAGE_MAP],
            ['name' => 'synthesis', 'type' => self::STAGE_SINGLE],
        ];
    }

    public function estimateStage(string $stage, array $params): float
    {
        $n = (int) ($params['n'] ?? 0);

        return $stage === 'analyse' ? $n * 0.10 : 1.00;
    }

    public function items(AiPipelineRun $run, string $stage): array
    {
        $n = (int) ($run->params['n'] ?? 0);

        return array_map(fn (int $i): string => "item-{$i}", range(0, max(0, $n - 1)));
    }

    public function handleItem(AiPipelineRun $run, string $stage, string $itemKey): array
    {
        return ['payload' => ['item' => $itemKey], 'cost' => 0.10];
    }

    public function handle(AiPipelineRun $run, string $stage): array
    {
        return ['payload' => ['summary' => 'done'], 'cost' => 1.00];
    }

    public function stageLabel(string $stage, int $done, int $total): string
    {
        return $stage === 'analyse' ? "Analysing {$done} of {$total}" : 'Summarising';
    }
}
