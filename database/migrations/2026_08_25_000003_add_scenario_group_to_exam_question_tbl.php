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
            $table->foreignId('scenario_group_id')
                ->nullable()
                ->after('question_presentation_type_id')
                ->constrained('question_scenario_groups_tbl')
                ->nullOnDelete();
            $table->unsignedInteger('scenario_question_order')
                ->default(0)
                ->after('scenario_group_id');

            $table->index(['scenario_group_id', 'scenario_question_order'], 'idx_questions_scenario_order');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('exam_question_tbl', function (Blueprint $table) {
            $table->dropIndex('idx_questions_scenario_order');
            $table->dropForeign(['scenario_group_id']);
            $table->dropColumn(['scenario_group_id', 'scenario_question_order']);
        });
    }
};
