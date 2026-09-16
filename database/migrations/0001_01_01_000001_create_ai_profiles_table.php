<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_profiles', function (Blueprint $table): void {
            $table->id();

            // Stable identifier used by application code (support_triage,
            // kanban_classifier, ...).
            $table->string('key', 80)->unique();

            $table->string('name')->nullable();
            $table->string('model', 120);

            // Used when the primary model fails (error or timeout) after its
            // retries are exhausted.
            $table->string('fallback_model', 120)->nullable();

            $table->unsignedInteger('max_input_tokens')->nullable();
            $table->unsignedInteger('max_output_tokens')->default(1024);

            // Null means "do not send temperature", i.e. the provider default.
            $table->decimal('temperature', 3, 2)->nullable();

            // When set, output is validated against it and re-asked once.
            $table->json('json_schema')->nullable();

            // The spend ceiling, in the configured local currency. An estimate
            // above this blocks the run before any tokens are spent.
            $table->decimal('max_cost_per_run', 10, 4)->nullable();

            // Attempts per model before falling back (1 = no retry).
            $table->unsignedTinyInteger('retries')->default(1);

            $table->boolean('enabled')->default(true);
            $table->text('system_prompt')->nullable();

            $table->json('settings')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_profiles');
    }
};
