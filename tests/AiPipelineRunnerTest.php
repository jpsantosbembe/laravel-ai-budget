<?php

namespace Bembe\AiBudget\Tests;

use Bembe\AiBudget\Exceptions\BudgetExceededException;
use Bembe\AiBudget\Jobs\RunPipelineStageItemJob;
use Bembe\AiBudget\Models\AiPipelineItem;
use Bembe\AiBudget\Models\AiPipelineRun;
use Bembe\AiBudget\Pipelines\AiPipeline;
use Bembe\AiBudget\Pipelines\AiPipelineRunner;
use Bembe\AiBudget\Tests\Fixtures\TwoStagePipeline;
use Illuminate\Bus\PendingBatch;
use Illuminate\Support\Facades\Bus;
use PHPUnit\Framework\Attributes\Test;

class AiPipelineRunnerTest extends TestCase
{
    #[Test]
    public function it_creates_a_run_and_enqueues_the_first_stage(): void
    {
        Bus::fake();

        $run = app(AiPipelineRunner::class)->dispatch(new TwoStagePipeline, ['n' => 3]);

        $this->assertSame(AiPipelineRun::STATUS_RUNNING, $run->status);
        $this->assertSame('analyse', $run->current_stage);
        $this->assertSame(3, $run->items()->where('stage', 'analyse')->count());

        Bus::assertBatchCount(1);
    }

    #[Test]
    public function it_blocks_the_dispatch_when_the_total_estimate_exceeds_the_master_profile_ceiling(): void
    {
        Bus::fake();

        $this->profile(['key' => TwoStagePipeline::PROFILE, 'max_cost_per_run' => 0.5]);

        $this->expectException(BudgetExceededException::class);

        try {
            // 10 items x 0.10 + 1.00 synthesis = 2.00, over the 0.50 ceiling.
            app(AiPipelineRunner::class)->dispatch(new TwoStagePipeline, ['n' => 10]);
        } finally {
            $this->assertSame(0, AiPipelineRun::count());
        }
    }

    #[Test]
    public function a_failed_item_does_not_take_down_the_stage_and_retry_only_reruns_it(): void
    {
        Bus::fake();

        $runner = app(AiPipelineRunner::class);
        $run = $runner->dispatch(new TwoStagePipeline, ['n' => 3]);

        $items = $run->items()->where('stage', 'analyse')->orderBy('item_key')->get();
        $items[0]->update(['status' => AiPipelineItem::STATUS_DONE, 'cost' => 0.1]);
        $items[1]->update(['status' => AiPipelineItem::STATUS_DONE, 'cost' => 0.1]);
        $items[2]->update(['status' => AiPipelineItem::STATUS_FAILED, 'error' => 'model timed out']);
        $run->update(['status' => AiPipelineRun::STATUS_FAILED]);

        $runner->retry($run->refresh());

        // Only the failed item is re-enqueued; the two that succeeded are not
        // paid for twice.
        Bus::assertBatched(function (PendingBatch $batch): bool {
            $jobs = $batch->jobs->all();

            return count($jobs) === 1
                && $jobs[0] instanceof RunPipelineStageItemJob
                && $jobs[0]->itemKey === 'item-2';
        });
    }

    #[Test]
    public function the_progress_snapshot_counts_processed_and_failed_items(): void
    {
        Bus::fake();

        $runner = app(AiPipelineRunner::class);
        $run = $runner->dispatch(new TwoStagePipeline, ['n' => 4]);

        $items = $run->items()->where('stage', 'analyse')->orderBy('item_key')->get();
        $items[0]->update(['status' => AiPipelineItem::STATUS_DONE]);
        $items[1]->update(['status' => AiPipelineItem::STATUS_FAILED]);

        $snapshot = $runner->progressSnapshot($run->refresh());

        $this->assertSame(4, $snapshot['total']);
        $this->assertSame(2, $snapshot['done']);
        $this->assertSame(1, $snapshot['failed']);
        $this->assertSame('Analysing 2 of 4', $snapshot['stage_label']);
    }

    #[Test]
    public function completing_the_last_stage_finishes_the_run(): void
    {
        Bus::fake();

        $runner = app(AiPipelineRunner::class);
        $run = $runner->dispatch(new TwoStagePipeline, ['n' => 1]);

        $run->items()->update(['status' => AiPipelineItem::STATUS_DONE]);
        $runner->completeStage((int) $run->id, 0);

        $run->refresh();
        $this->assertSame('synthesis', $run->current_stage);
        $this->assertSame(AiPipelineItem::SINGLE_KEY, $run->items()->where('stage', 'synthesis')->sole()->item_key);

        $run->items()->update(['status' => AiPipelineItem::STATUS_DONE]);
        $runner->completeStage((int) $run->id, 1);

        $this->assertSame(AiPipelineRun::STATUS_DONE, $run->refresh()->status);
        $this->assertNotNull($run->finished_at);
    }

    #[Test]
    public function a_run_that_ends_with_failed_items_is_marked_failed(): void
    {
        Bus::fake();

        $runner = app(AiPipelineRunner::class);
        $run = $runner->dispatch(new TwoStagePipeline, ['n' => 1]);

        $run->items()->update(['status' => AiPipelineItem::STATUS_DONE]);
        $runner->completeStage((int) $run->id, 0);

        $run->refresh()->items()->where('stage', 'synthesis')->update(['status' => AiPipelineItem::STATUS_FAILED]);
        $runner->completeStage((int) $run->id, 1);

        $run->refresh();
        $this->assertSame(AiPipelineRun::STATUS_FAILED, $run->status);
        $this->assertStringContainsString('failed in stage', (string) $run->error);
    }

    #[Test]
    public function a_cancelled_run_stops_advancing(): void
    {
        Bus::fake();

        $runner = app(AiPipelineRunner::class);
        $run = $runner->dispatch(new TwoStagePipeline, ['n' => 2]);

        $runner->cancel($run);
        $runner->completeStage((int) $run->id, 0);

        $run->refresh();
        $this->assertSame(AiPipelineRun::STATUS_CANCELLED, $run->status);
        $this->assertSame('analyse', $run->current_stage);
    }

    #[Test]
    public function stages_declare_their_type(): void
    {
        $pipeline = new TwoStagePipeline;

        $this->assertSame(AiPipeline::STAGE_MAP, $pipeline->stageType('analyse'));
        $this->assertSame(AiPipeline::STAGE_SINGLE, $pipeline->stageType('synthesis'));
        $this->assertSame(0, $pipeline->stageIndex('analyse'));
        $this->assertNull($pipeline->stageIndex('nope'));
    }
}
