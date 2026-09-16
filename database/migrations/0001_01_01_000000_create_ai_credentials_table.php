<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_credentials', function (Blueprint $table): void {
            $table->id();

            // Stable identifier profiles point at.
            $table->string('key', 80)->unique();
            $table->string('name')->nullable();

            // Selects the transport in config('ai-budget.transports').
            $table->string('provider', 40)->default('openrouter');

            // Cast to encrypted:array on the model — never stored in clear text.
            $table->text('credentials')->nullable();

            $table->boolean('enabled')->default(true);

            $table->json('settings')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_credentials');
    }
};
