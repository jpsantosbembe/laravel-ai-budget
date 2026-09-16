<?php

namespace Bembe\AiBudget\Support;

use Illuminate\Contracts\Config\Repository as Config;

/**
 * Turns token counts into money.
 *
 * Provider prices live in config/ai-pricing.php as USD per MILLION tokens.
 * Models missing from that table fall back to the configured generic price:
 * overestimating is deliberate, because a zero price would silently disable
 * the spend ceiling for exactly the models nobody registered.
 *
 * The local-currency conversion reads `ai-budget.fx_rate`, which may be a
 * float or a callable, so a live rate can be plugged in without touching
 * this class.
 */
class CostCalculator
{
    public function __construct(private readonly Config $config) {}

    public function costUsd(string $model, int $inputTokens, int $outputTokens): float
    {
        $price = $this->priceFor($model);

        return ($inputTokens / 1_000_000) * $price['input']
            + ($outputTokens / 1_000_000) * $price['output'];
    }

    public function toLocal(float $usd): float
    {
        return $usd * $this->fxRate();
    }

    public function fxRate(): float
    {
        $rate = $this->config->get('ai-budget.fx_rate', 1.0);

        if (is_callable($rate)) {
            $rate = $rate();
        }

        $rate = (float) $rate;

        return $rate > 0 ? $rate : 1.0;
    }

    public function currency(): string
    {
        return (string) $this->config->get('ai-budget.currency', 'USD');
    }

    /**
     * @return array{input: float, output: float} USD per million tokens
     */
    public function priceFor(string $model): array
    {
        /** @var array<string, array{input: float|int, output: float|int}> $models */
        $models = (array) $this->config->get('ai-pricing.models', []);

        // Direct key access, not dot notation: model names contain dots
        // ("google/gemini-2.5-flash"), which config() would treat as nesting.
        $price = $models[$model]
            ?? $this->config->get('ai-pricing.fallback', ['input' => 0.0, 'output' => 0.0]);

        return [
            'input' => (float) $price['input'],
            'output' => (float) $price['output'],
        ];
    }

    /**
     * Approximate the token count of a string before sending it, which is what
     * the ceiling is checked against.
     */
    public function approximateTokens(string $text): int
    {
        $charsPerToken = max(1, (int) $this->config->get('ai-budget.chars_per_token', 4));

        return (int) ceil(mb_strlen($text) / $charsPerToken);
    }
}
