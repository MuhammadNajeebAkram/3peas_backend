<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_models', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 50)->default('openai');
            $table->string('name');
            $table->string('model_key', 150);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);
            // A nullable unique value enforces one default even for concurrent writes.
            $table->unsignedTinyInteger('default_slot')
                ->storedAs('CASE WHEN is_default = 1 THEN 1 ELSE NULL END')->unique();
            $table->decimal('input_price_per_million', 14, 6)->nullable();
            $table->decimal('cached_input_price_per_million', 14, 6)->nullable();
            $table->decimal('output_price_per_million', 14, 6)->nullable();
            $table->char('currency', 3)->default('USD');
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['provider', 'model_key']);
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_models');
    }
};
