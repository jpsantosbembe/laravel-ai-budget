<?php

namespace Bembe\AiBudget\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One execution of an AiPipeline. The run holds the macro state — status,
 * current stage, estimated and actual cost — while the fine-grained work lives
 * in ai_pipeline_items, one row per item per stage.
 *
 * @property int $id
 * @property string $pipeline_key
 * @property string $status
 * @property string|null $current_stage
 * @property array<string, mixed>|null $params
 * @property int|null $created_by
 * @property float $estimated_cost
 * @property float $actual_cost
 * @property string|null $error
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property-read Collection<int, AiPipelineItem> $items
 */
class AiPipelineRun extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_DONE = 'done';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    protected $table = 'ai_pipeline_runs';

    protected $fillable = [
        'pipeline_key',
        'status',
        'current_stage',
        'params',
        'created_by',
        'estimated_cost',
        'actual_cost',
        'error',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'params' => 'array',
            'estimated_cost' => 'float',
            'actual_cost' => 'float',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(AiPipelineItem::class, 'run_id');
    }

    public function isFinished(): bool
    {
        return in_array($this->status, [self::STATUS_DONE, self::STATUS_FAILED, self::STATUS_CANCELLED], true);
    }
}
