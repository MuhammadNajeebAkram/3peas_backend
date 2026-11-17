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
            $table->foreignId('slo_topic_id')->nullable()->after('scenario_question_order')->constrained('slo_topic_tbl')->nullOnDelete();
            $table->index('slo_topic_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('exam_question_tbl', function (Blueprint $table) {
            $table->dropForeign(['slo_topic_id']);
            $table->dropColumn('slo_topic_id');
        });
    }
};
