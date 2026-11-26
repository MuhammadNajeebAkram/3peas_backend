<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_feature_settings', function (Blueprint $table) {
            $table->id();
            $table->string('feature', 64);
            $table->string('environment', 32);
            $table->string('provider', 32);
            $table->string('model', 120);
            $table->boolean('is_enabled')->default(true);
            $table->unsignedSmallInteger('timeout')->default(25);
            $table->unsignedSmallInteger('max_output_tokens')->default(400);
            $table->unsignedBigInteger('updated_by')->nullable()->index();
            $table->timestamps();

            $table->unique(['feature', 'environment'], 'ai_feature_settings_feature_env_unique');
        });

        Schema::create('ai_feature_setting_audits', function (Blueprint $table) {
            $table->id();
            $table->string('feature', 64);
            $table->string('environment', 32);
            $table->unsignedBigInteger('admin_user_id')->nullable()->index();
            $table->string('previous_provider', 32)->nullable();
            $table->string('new_provider', 32);
            $table->string('previous_model', 120)->nullable();
            $table->string('new_model', 120);
            $table->boolean('previous_is_enabled')->nullable();
            $table->boolean('new_is_enabled');
            $table->unsignedSmallInteger('previous_timeout')->nullable();
            $table->unsignedSmallInteger('new_timeout');
            $table->unsignedSmallInteger('previous_max_output_tokens')->nullable();
            $table->unsignedSmallInteger('new_max_output_tokens');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['feature', 'environment', 'created_at'], 'ai_feature_audits_feature_env_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_feature_setting_audits');
        Schema::dropIfExists('ai_feature_settings');
    }
};
