<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('exam_question_tbl', function (Blueprint $table) {
            $table->foreignId('ai_provider_id')
                ->nullable()
                ->after('ai_question_generation_id')
                ->constrained('ai_providers')
                ->nullOnDelete();

            $table->foreignId('ai_model_id')
                ->nullable()
                ->after('ai_provider_id')
                ->constrained('ai_models')
                ->nullOnDelete();

            $table->index(['ai_provider_id', 'ai_model_id']);
        });

        Schema::table('question_scenario_groups_tbl', function (Blueprint $table) {
            $table->foreignId('ai_provider_id')
                ->nullable()
                ->after('question_presentation_type_id')
                ->constrained('ai_providers')
                ->nullOnDelete();

            $table->foreignId('ai_model_id')
                ->nullable()
                ->after('ai_provider_id')
                ->constrained('ai_models')
                ->nullOnDelete();

            $table->index(['ai_provider_id', 'ai_model_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('question_scenario_groups_tbl', function (Blueprint $table) {
            $table->dropIndex(['ai_provider_id', 'ai_model_id']);
            $table->dropForeign(['ai_model_id']);
            $table->dropForeign(['ai_provider_id']);
            $table->dropColumn(['ai_provider_id', 'ai_model_id']);
        });

        Schema::table('exam_question_tbl', function (Blueprint $table) {
            $table->dropIndex(['ai_provider_id', 'ai_model_id']);
            $table->dropForeign(['ai_model_id']);
            $table->dropForeign(['ai_provider_id']);
            $table->dropColumn(['ai_provider_id', 'ai_model_id']);
        });
    }
};
