<?php

namespace Bembe\AiBudget\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * One row per model call, with the real cost attached.
 *
 * `subject` is an optional polymorphic link to whatever your app was doing at
 * the time (a conversation, a card, an order), so spend can be attributed
 * without this package knowing anything about your domain.
 *
 * @property int $id
 * @property string $profile_key
 * @property string|null $subject_type
 * @property string|null $subject_id
 * @property string $model
 * @property int $input_tokens
 * @property int $output_tokens
 * @property int $latency_ms
 * @property float $cost_usd
 * @property float $cost_local
 * @property array<string, mixed>|null $context
 * @property Carbon $created_at
 */
class AiUsageLog extends Model
{
    protected $table = 'ai_usage_logs';

    public $timestamps = false;

    protected $fillable = [
        'profile_key',
        'subject_type',
        'subject_id',
        'model',
        'input_tokens',
        'output_tokens',
        'latency_ms',
        'cost_usd',
        'cost_local',
        'context',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'latency_ms' => 'integer',
            'cost_usd' => 'float',
            'cost_local' => 'float',
            'context' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
