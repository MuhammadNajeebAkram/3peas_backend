<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_providers', function (Blueprint $table) {
            $table->id();
            $table->string('key', 50)->unique();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->json('settings')->nullable();
            $table->timestamps();
        });
        $keys = DB::table('ai_models')->distinct()->pluck('provider')->merge(['openai', 'gemini'])->unique();
        foreach ($keys as $key) {
            DB::table('ai_providers')->insert([
                'key' => $key, 'name' => match ($key) {
                    'openai' => 'OpenAI', 'gemini' => 'Google Gemini', default => $key
                },
                'is_active' => $key === 'openai', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        Schema::table('ai_models', fn (Blueprint $table) => $table->foreignId('ai_provider_id')->nullable()->constrained('ai_providers')->restrictOnDelete());
        foreach (DB::table('ai_providers')->get() as $provider) {
            DB::table('ai_models')->where('provider', $provider->key)->update(['ai_provider_id' => $provider->id]);
        }
        Schema::table('ai_models', function (Blueprint $table) {
            $table->unsignedBigInteger('ai_provider_id')->nullable(false)->change();
            $table->dropUnique(['provider', 'model_key']);
            $table->unique(['ai_provider_id', 'model_key']);
            $table->dropColumn('provider');
        });
    }

    public function down(): void
    {
        Schema::table('ai_models', fn (Blueprint $table) => $table->string('provider', 50)->default('openai'));
        foreach (DB::table('ai_providers')->get() as $provider) {
            DB::table('ai_models')->where('ai_provider_id', $provider->id)->update(['provider' => $provider->key]);
        }
        Schema::table('ai_models', function (Blueprint $table) {
            $table->dropUnique(['ai_provider_id', 'model_key']);
            $table->dropForeign(['ai_provider_id']);
            $table->dropColumn('ai_provider_id');
            $table->unique(['provider', 'model_key']);
        });
        Schema::dropIfExists('ai_providers');
    }
};
