<?php

namespace Bembe\AiBudget\Transport;

use Bembe\AiBudget\ChatResult;
use Bembe\AiBudget\Exceptions\InvalidCredentialException;
use Bembe\AiBudget\Exceptions\RateLimitedException;
use Illuminate\Support\Facades\Http;
use Psr\Log\LoggerInterface;

/**
 * OpenRouter transport (https://openrouter.ai/api/v1/chat/completions).
 *
 * Stateless apart from the API key, so one instance per credential.
 */
class OpenRouterTransport implements ChatTransport
{
    private const ENDPOINT = 'https://openrouter.ai/api/v1/chat/completions';

    /**
     * @param  array<string, mixed>  $settings
     */
    public function __construct(
        private readonly string $apiKey,
        private readonly LoggerInterface $logger,
        private readonly array $settings = [],
    ) {}

    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     */
    public function chat(
        string $systemPrompt,
        array $messages,
        string $model,
        int $maxTokens,
        ?float $temperature = null,
        int $timeoutSeconds = 30,
    ): ChatResult {
        $payload = [
            'model' => $model,
            'max_tokens' => $maxTokens,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ...$messages,
            ],
        ];

        if ($temperature !== null) {
            $payload['temperature'] = $temperature;
        }

        $this->logger->info('[ai-budget] OpenRouter request', [
            'model' => $model,
            'messages_count' => count($messages),
        ]);

        $startedAt = microtime(true);

        $response = Http::withHeaders($this->headers())
            ->timeout($timeoutSeconds)
            ->post(self::ENDPOINT, $payload);

        $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);

        if ($response->failed()) {
            $this->logger->error('[ai-budget] OpenRouter error', [
                'status' => $response->status(),
                'body' => $response->body(),
                'latency_ms' => $latencyMs,
            ]);

            if ($response->status() === 401) {
                throw new InvalidCredentialException('OpenRouter API key is invalid or revoked.');
            }

            if ($response->status() === 429) {
                throw new RateLimitedException('OpenRouter rate limit reached.');
            }

            throw new \RuntimeException('OpenRouter API error: '.$response->status());
        }

        $rawContent = $response->json('choices.0.message.content');
        $contentWasNull = ! is_string($rawContent);

        if ($contentWasNull) {
            // A null content with a finish_reason is a truncated or filtered
            // completion, not a transport failure. It is normalised to an empty
            // string so the caller still gets the token counts — the provider
            // charged for them either way.
            $this->logger->warning('[ai-budget] OpenRouter response had null content', [
                'model' => $model,
                'finish_reason' => $response->json('choices.0.finish_reason'),
                'latency_ms' => $latencyMs,
            ]);
        }

        return new ChatResult(
            content: $contentWasNull ? '' : $rawContent,
            inputTokens: (int) ($response->json('usage.prompt_tokens') ?? 0),
            outputTokens: (int) ($response->json('usage.completion_tokens') ?? 0),
            latencyMs: $latencyMs,
            contentWasNull: $contentWasNull,
        );
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        $headers = [
            'Authorization' => 'Bearer '.$this->apiKey,
            'Content-Type' => 'application/json',
        ];

        // OpenRouter uses these for attribution on its public leaderboards.
        if (is_string($referer = $this->settings['http_referer'] ?? null)) {
            $headers['HTTP-Referer'] = $referer;
        }

        if (is_string($title = $this->settings['x_title'] ?? null)) {
            $headers['X-Title'] = $title;
        }

        return $headers;
    }
}
