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
        Schema::create('freemium_access_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained('freemium_campaigns')->restrictOnDelete();

            $table->enum('target_scope', [
                'global',
                'curriculum_board',
                'class',
                'subject',
                'book',
                'chapter',
                'topic',
                'question',
                'model_paper',
                'past_paper',
            ])->comment('Explicit intended academic hierarchy level');

            $table->enum('target_status', ['active', 'orphaned'])->default('active');

            // Academic Hierarchy Foreign Keys (Fail-Closed on Delete: Set Null)
            $table->foreignId('curriculum_board_id')->nullable()->constrained('curriculum_board_tbl')->nullOnDelete();
            $table->foreignId('class_id')->nullable()->constrained('class_tbl')->nullOnDelete();
            $table->foreignId('subject_id')->nullable()->constrained('subject_tbl')->nullOnDelete();
            $table->foreignId('book_id')->nullable()->constrained('book_tbl')->nullOnDelete();
            $table->foreignId('chapter_id')->nullable()->constrained('book_unit_tbl')->nullOnDelete();
            $table->foreignId('topic_id')->nullable()->constrained('book_unit_topic_tbl')->nullOnDelete();

            // Leaf Node Foreign Keys
            $table->foreignId('question_id')->nullable()->constrained('exam_question_tbl')->nullOnDelete();
            $table->foreignId('model_paper_id')->nullable()->constrained('model_papers')->nullOnDelete();
            $table->foreignId('past_paper_id')->nullable()->constrained('tst_past_papers')->nullOnDelete();

            // 10-Dimension Three-State Permission Matrix
            $table->enum('perm_mcqs', ['allow', 'deny', 'inherit'])->default('inherit');
            $table->enum('perm_short_questions', ['allow', 'deny', 'inherit'])->default('inherit');
            $table->enum('perm_long_questions', ['allow', 'deny', 'inherit'])->default('inherit');
            $table->enum('perm_exercise_questions', ['allow', 'deny', 'inherit'])->default('inherit');
            $table->enum('perm_supplementary_questions', ['allow', 'deny', 'inherit'])->default('inherit');
            $table->enum('perm_answers', ['allow', 'deny', 'inherit'])->default('inherit');
            $table->enum('perm_explanations', ['allow', 'deny', 'inherit'])->default('inherit');
            $table->enum('perm_topic_contents', ['allow', 'deny', 'inherit'])->default('inherit');
            $table->enum('perm_past_papers', ['allow', 'deny', 'inherit'])->default('inherit');
            $table->enum('perm_model_papers', ['allow', 'deny', 'inherit'])->default('inherit');

            $table->unsignedInteger('rule_priority')->default(10);
            $table->json('academic_target_snapshot')->comment('Immutable copy of target metadata');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->index('campaign_id', 'idx_far_campaign');
            $table->index(['target_scope', 'target_status'], 'idx_far_target_scope');
            $table->index(
                ['curriculum_board_id', 'class_id', 'subject_id', 'book_id', 'chapter_id', 'topic_id'],
                'idx_far_hierarchy_lookup'
            );
            $table->index(
                ['question_id', 'model_paper_id', 'past_paper_id'],
                'idx_far_leaf_lookup'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('freemium_access_rules');
    }
};
