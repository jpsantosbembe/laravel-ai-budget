<?php

namespace Bembe\AiBudget\Pipelines;

/**
 * Registry of pipelines, a singleton in the container. Orchestration jobs
 * serialise only run_id + stage + item_key and rebuild the pipeline from its
 * key through resolve().
 */
class AiPipelineRegistry
{
    /** @var array<string, AiPipeline|class-string<AiPipeline>> */
    private array $pipelines = [];

    /**
     * @param  AiPipeline|class-string<AiPipeline>  $pipeline
     */
    public function register(AiPipeline|string $pipeline): void
    {
        $instance = is_string($pipeline) ? app($pipeline) : $pipeline;

        $this->pipelines[$instance->key()] = $instance;
    }

    public function resolve(string $key): AiPipeline
    {
        $pipeline = $this->pipelines[$key] ?? null;

        if ($pipeline === null) {
            throw new \RuntimeException("Pipeline '{$key}' is not registered in the AiPipelineRegistry.");
        }

        return is_string($pipeline) ? app($pipeline) : $pipeline;
    }

    public function has(string $key): bool
    {
        return isset($this->pipelines[$key]);
    }
}
