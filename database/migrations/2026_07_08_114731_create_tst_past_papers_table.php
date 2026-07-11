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
        Schema::create('tst_past_papers', function (Blueprint $table) {
            $table->id();
            $table->string('paper_title');
            $table->string('paper_slug');
            $table->foreignId('board_id')->constrained('board_tbl')->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained('subject_tbl')->cascadeOnDelete();
            $table->foreignId('class_id')->constrained('class_tbl')->cascadeOnDelete();
            $table->foreignId('session_id')->constrained('exam_session_tbl')->cascadeOnDelete();
            $table->integer('group');
            $table->integer('year');
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique('paper_title');
            $table->unique('paper_slug');
            $table->index('is_active');
            $table->index(
                ['is_active', 'class_id', 'subject_id', 'board_id', 'year', 'session_id', 'group'],
                'tst_pp_search_idx'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tst_past_papers');
    }
};
