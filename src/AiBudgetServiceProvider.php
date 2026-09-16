<?php

namespace Bembe\AiBudget;

use Bembe\AiBudget\Pipelines\AiPipelineRegistry;
use Bembe\AiBudget\Pipelines\AiPipelineRunner;
use Bembe\AiBudget\Schema\SchemaValidator;
use Bembe\AiBudget\Support\CostCalculator;
use Bembe\AiBudget\Transport\TransportFactory;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;

class AiBudgetServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/ai-budget.php', 'ai-budget');
        $this->mergeConfigFrom(__DIR__.'/../config/ai-pricing.php', 'ai-pricing');

        // A dedicated logger instance so the package can write to its own
        // channel without the application having to configure one.
        $this->app->when([
            AiRunner::class,
            TransportFactory::class,
            AiPipelineRunner::class,
            Jobs\RunPipelineStageItemJob::class,
        ])->needs(LoggerInterface::class)->give(function ($app) {
            $channel = $app->make(Config::class)->get('ai-budget.log_channel');

            return $channel ? Log::channel($channel) : Log::getLogger();
        });

        $this->app->singleton(CostCalculator::class);
        $this->app->singleton(AiPipelineRegistry::class);
        $this->app->singleton(AiPipelineRunner::class);

        $this->app->bind(SchemaValidator::class, function ($app) {
            $class = $app->make(Config::class)->get(
                'ai-budget.schema_validator',
                Schema\SubsetSchemaValidator::class,
            );

            return $app->make($class);
        });
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/ai-budget.php' => config_path('ai-budget.php'),
                __DIR__.'/../config/ai-pricing.php' => config_path('ai-pricing.php'),
            ], 'ai-budget-config');

            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'ai-budget-migrations');
        }
    }
}
