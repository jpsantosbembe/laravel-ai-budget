<?php

namespace Bembe\AiBudget\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * How one AI task runs: model, fallback model, token limits, temperature,
 * expected output schema, retries and — the point of this package — the spend
 * ceiling for a single run.
 *
 * The API key is not here. It lives on the AiCredential named by
 * credentialKey(), which defaults to the profile's own key.
 *
 * Consumers execute through AiRunner::run() and never read a profile directly.
 *
 * @property int $id
 * @property string $key
 * @property string|null $name
 * @property string $model
 * @property string|null $fallback_model
 * @property int|null $max_input_tokens
 * @property int $max_output_tokens
 * @property float|null $temperature
 * @property array<string, mixed>|null $json_schema
 * @property float|null $max_cost_per_run
 * @property int $retries
 * @property bool $enabled
 * @property string|null $system_prompt
 * @property array<string, mixed>|null $settings
 */
class AiProfile extends Model
{
    protected $table = 'ai_profiles';

    protected $fillable = [
        'key',
        'name',
        'model',
        'fallback_model',
        'max_input_tokens',
        'max_output_tokens',
        'temperature',
        'json_schema',
        'max_cost_per_run',
        'retries',
        'enabled',
        'system_prompt',
        'settings',
    ];

    protected function casts(): array
    {
        return [
            'max_input_tokens' => 'integer',
            'max_output_tokens' => 'integer',
            'temperature' => 'float',
            'json_schema' => 'array',
            'max_cost_per_run' => 'float',
            'retries' => 'integer',
            'enabled' => 'boolean',
            'settings' => 'array',
        ];
    }

    /**
     * Key of the AiCredential holding the API key for this profile. Defaults to
     * the profile's own key; override with settings.credential_key when several
     * profiles share one credential.
     */
    public function credentialKey(): string
    {
        $settings = (array) ($this->settings ?? []);

        $key = $settings['credential_key'] ?? null;

        return is_string($key) && $key !== '' ? $key : (string) $this->key;
    }

    public function isRunnable(): bool
    {
        return $this->enabled && ! empty($this->model);
    }

    /**
     * HTTP timeout for the call. Reduce/synthesis stages usually need more than
     * the default.
     */
    public function timeoutSeconds(int $default = 30): int
    {
        $settings = (array) ($this->settings ?? []);

        return (int) (($settings['timeout_seconds'] ?? null) ?: $default);
    }
}
