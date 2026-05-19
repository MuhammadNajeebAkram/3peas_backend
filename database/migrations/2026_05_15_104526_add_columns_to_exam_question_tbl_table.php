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
            $table->enum('status', ['draft', 'under_review', 'published', 'archived'])->default('draft');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('explanation')->nullable();
            $table->text('explanation_um')->nullable();
            $table->string('explanation_video_url')->nullable();
            $table->foreignId('unit_id')
                ->nullable()
                ->constrained('book_unit_tbl')
                ->nullOnDelete();
            $table->foreignId('book_id')
                ->nullable()
                ->constrained('book_tbl')
                ->nullOnDelete();

            $table->index('difficulty');
            $table->index('status');
            $table->index([
                'book_id',
                'unit_id',
            ], 'idx_questions_book_unit');
            $table->index([
                'book_id',
                'unit_id',
                'topic_id',
            ], 'idx_questions_hierarchy');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('exam_question_tbl', function (Blueprint $table) {
            $table->dropIndex(['difficulty']);
            $table->dropIndex(['status']);
            $table->dropIndex('idx_questions_book_unit');
            $table->dropIndex('idx_questions_hierarchy');

            $table->dropForeign(['created_by']);
            $table->dropForeign(['updated_by']);
            $table->dropForeign(['reviewed_by']);
            $table->dropForeign(['unit_id']);
            $table->dropForeign(['book_id']);

            $table->dropColumn([
                'status',
                'created_by',
                'updated_by',
                'reviewed_by',
                'reviewed_at',
                'explanation',
                'explanation_um',
                'explanation_video_url',
                'unit_id',
                'book_id',
            ]);
        });
    }
};
