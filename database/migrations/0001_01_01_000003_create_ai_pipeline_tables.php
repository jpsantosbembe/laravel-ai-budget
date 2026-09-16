<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_pipeline_runs', function (Blueprint $table): void {
            $table->id();

            $table->string('pipeline_key', 80)->index();

            // pending | running | done | failed | cancelled
            $table->string('status', 20)->default('pending')->index();

            // The stage retry() resumes.
            $table->string('current_stage', 80)->nullable();

            $table->json('params')->nullable();

            // No foreign key: the package must not assume a users table.
            $table->unsignedBigInteger('created_by')->nullable()->index();

            $table->decimal('estimated_cost', 12, 4)->default(0);
            $table->decimal('actual_cost', 12, 4)->default(0);

            $table->text('error')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();

            $table->timestamps();
        });

        Schema::create('ai_pipeline_items', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('run_id')->constrained('ai_pipeline_runs')->cascadeOnDelete();

            $table->string('stage', 80);
            $table->string('item_key', 120);

            // pending | running | done | failed
            $table->string('status', 20)->default('pending')->index();

            // What the next stage reads.
            $table->json('payload')->nullable();

            $table->decimal('cost', 12, 4)->default(0);

            $table->text('error')->nullable();

            $table->timestamps();

            $table->unique(['run_id', 'stage', 'item_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_pipeline_items');
        Schema::dropIfExists('ai_pipeline_runs');
    }
};
