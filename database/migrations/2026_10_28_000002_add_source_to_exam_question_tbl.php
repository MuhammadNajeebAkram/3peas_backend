<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('exam_question_tbl', function (Blueprint $table) {
            // manual = typed in the question editor, import = bulk import, ai = AI generation batch.
            $table->string('source', 20)->default('manual')->after('status')->index();
            $table->foreignId('ai_question_generation_id')
                ->nullable()
                ->after('source')
                ->constrained('ai_question_generations')
                ->nullOnDelete();
        });

        // Backfill questions already saved from AI generation batches.
        DB::table('ai_question_generations')
            ->whereNotNull('saved_questions')
            ->select('id', 'saved_questions')
            ->orderBy('id')
            ->chunkById(200, function ($generations) {
                foreach ($generations as $generation) {
                    $questionIds = collect(json_decode($generation->saved_questions, true) ?: [])
                        ->filter(fn ($id) => is_numeric($id))
                        ->map(fn ($id) => (int) $id)
                        ->values()
                        ->all();

                    if (empty($questionIds)) {
                        continue;
                    }

                    DB::table('exam_question_tbl')
                        ->whereIn('id', $questionIds)
                        ->update([
                            'source' => 'ai',
                            'ai_question_generation_id' => $generation->id,
                        ]);
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('exam_question_tbl', function (Blueprint $table) {
            $table->dropForeign(['ai_question_generation_id']);
            $table->dropIndex(['source']);
            $table->dropColumn(['source', 'ai_question_generation_id']);
        });
    }
};

