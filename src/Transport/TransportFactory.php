<?php

namespace Bembe\AiBudget\Transport;

use Bembe\AiBudget\Models\AiCredential;
use Illuminate\Contracts\Config\Repository as Config;
use Psr\Log\LoggerInterface;

/**
 * Builds the ChatTransport for a credential, based on its `provider` column
 * and the `ai-budget.transports` map.
 */
class TransportFactory
{
    public function __construct(
        private readonly Config $config,
        private readonly LoggerInterface $logger,
    ) {}

    public function for(AiCredential $credential): ChatTransport
    {
        $provider = $credential->provider ?: (string) $this->config->get('ai-budget.default_transport', 'openrouter');

        /** @var array<string, class-string<ChatTransport>> $map */
        $map = (array) $this->config->get('ai-budget.transports', []);

        $class = $map[$provider] ?? null;

        if ($class === null) {
            throw new \RuntimeException("No transport registered for provider '{$provider}'. Add it to config/ai-budget.php.");
        }

        $apiKey = $credential->apiKey();

        if ($apiKey === null || $apiKey === '') {
            throw new \RuntimeException("Credential '{$credential->key}' has no API key.");
        }

        return new $class($apiKey, $this->logger, $credential->settings ?? []);
    }
}
