<?php

namespace Bembe\AiBudget\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A provider credential. Profiles point at one of these by key, so several
 * profiles can share a single API key.
 *
 * `credentials` is cast to encrypted:array — the key is never stored in clear
 * text and never appears in a model diff.
 *
 * @property int $id
 * @property string $key
 * @property string|null $name
 * @property string $provider
 * @property array<string, mixed>|null $credentials
 * @property bool $enabled
 * @property array<string, mixed>|null $settings
 */
class AiCredential extends Model
{
    protected $table = 'ai_credentials';

    protected $fillable = [
        'key',
        'name',
        'provider',
        'credentials',
        'enabled',
        'settings',
    ];

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
            'enabled' => 'boolean',
            'settings' => 'array',
        ];
    }

    public function apiKey(): ?string
    {
        $credentials = (array) ($this->credentials ?? []);

        $key = $credentials['api_key'] ?? null;

        return is_string($key) && $key !== '' ? $key : null;
    }

    public function isUsable(): bool
    {
        return $this->enabled && $this->apiKey() !== null;
    }
}
