<?php

namespace Bembe\AiBudget\Transport;

use Bembe\AiBudget\ChatResult;

/**
 * A raw chat completion call to one provider.
 *
 * Implementations are stateless apart from the credential handed to them at
 * construction time, and MUST translate provider failures into the package's
 * exceptions so the runner's retry and fallback logic behaves the same across
 * providers:
 *
 * - invalid or revoked key  -> InvalidCredentialException
 * - rate limited            -> RateLimitedException
 * - anything else           -> \RuntimeException
 */
interface ChatTransport
{
    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     * @param  float|null  $temperature  null means "do not send", i.e. the provider default
     */
    public function chat(
        string $systemPrompt,
        array $messages,
        string $model,
        int $maxTokens,
        ?float $temperature = null,
        int $timeoutSeconds = 30,
    ): ChatResult;
}
