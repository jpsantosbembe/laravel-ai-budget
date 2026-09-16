<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_usage_logs', function (Blueprint $table): void {
            $table->id();

            $table->string('profile_key', 80)->index();

            // Optional link to whatever the application was doing. No foreign
            // key on purpose: deleting the subject must not erase the spend
            // history.
            $table->string('subject_type')->nullable();
            $table->string('subject_id')->nullable();

            $table->string('model', 120);
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('latency_ms')->default(0);

            $table->decimal('cost_usd', 12, 6)->default(0);
            $table->decimal('cost_local', 12, 6)->default(0);

            $table->json('context')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['subject_type', 'subject_id']);
            $table->index(['profile_key', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage_logs');
    }
};
