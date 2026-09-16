<?php

namespace Bembe\AiBudget\Tests;

use Bembe\AiBudget\AiBudgetServiceProvider;
use Bembe\AiBudget\Models\AiCredential;
use Bembe\AiBudget\Models\AiProfile;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [AiBudgetServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        // Required by the encrypted:array cast on AiCredential.
        $app['config']->set('app.key', 'base64:MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY=');

        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        // Batches are stored on the same in-memory connection as everything else.
        $app['config']->set('queue.batching.database', 'testing');

        // A fixed rate keeps the assertions readable: 1 USD = 5 local units.
        $app['config']->set('ai-budget.currency', 'BRL');
        $app['config']->set('ai-budget.fx_rate', 5.0);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        Schema::create('job_batches', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('name');
            $table->integer('total_jobs');
            $table->integer('pending_jobs');
            $table->integer('failed_jobs');
            $table->longText('failed_job_ids');
            $table->mediumText('options')->nullable();
            $table->integer('cancelled_at')->nullable();
            $table->integer('created_at');
            $table->integer('finished_at')->nullable();
        });
    }

    protected function credential(array $attributes = []): AiCredential
    {
        return AiCredential::create(array_merge([
            'key' => 'default',
            'provider' => 'openrouter',
            'credentials' => ['api_key' => 'sk-test'],
            'enabled' => true,
        ], $attributes));
    }

    protected function profile(array $attributes = []): AiProfile
    {
        return AiProfile::create(array_merge([
            'key' => 'default',
            'name' => 'Default',
            'model' => 'openai/gpt-4o-mini',
            'max_output_tokens' => 1000,
            'retries' => 1,
            'enabled' => true,
            'system_prompt' => 'You are helpful.',
        ], $attributes));
    }
}
