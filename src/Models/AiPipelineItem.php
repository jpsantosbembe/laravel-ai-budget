<?php

namespace Bembe\AiBudget\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One unit of work in a pipeline stage. `map` stages produce one item per key;
 * `single` stages produce one item under SINGLE_KEY. `payload` carries the
 * result the next stage reads.
 *
 * @property int $id
 * @property int $run_id
 * @property string $stage
 * @property string $item_key
 * @property string $status
 * @property array<string, mixed>|null $payload
 * @property float $cost
 * @property string|null $error
 */
class AiPipelineItem extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_DONE = 'done';

    public const STATUS_FAILED = 'failed';

    public const SINGLE_KEY = '__single';

    protected $table = 'ai_pipeline_items';

    protected $fillable = [
        'run_id',
        'stage',
        'item_key',
        'status',
        'payload',
        'cost',
        'error',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'cost' => 'float',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(AiPipelineRun::class, 'run_id');
    }
}
