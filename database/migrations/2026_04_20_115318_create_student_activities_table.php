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
        Schema::create('student_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')
                ->constrained('web_users')
                ->cascadeOnDelete();

            $table->string('activity_type');
            // examples:
            // practice_completed
            // test_completed
            // mock_completed
            // chapter_viewed
            // lesson_opened
            // video_watched
            // workshop_registered

            $table->string('title');
            $table->text('description')->nullable();

            $table->foreignId('offered_program_id')
                ->nullable()
                ->constrained('offered_programs')
                ->nullOnDelete();

            $table->foreignId('subject_id')
                ->nullable()
                ->constrained('subject_tbl')
                ->nullOnDelete();

            $table->foreignId('unit_id')
                ->nullable()
                ->constrained('book_unit_tbl')
                ->nullOnDelete();

            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('reference_type')->nullable();
            // examples:
            // practice_session
            // test_attempt
            // workshop_registration
            // chapter
            // lesson

            $table->json('meta')->nullable();

            $table->timestamp('activity_at')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'activity_at'], 'stu_act_user_time_idx');
            $table->index(['user_id', 'activity_type'], 'stu_act_user_type_idx');
            $table->index(['reference_type', 'reference_id'], 'stu_act_ref_idx');
            $table->index(['user_id', 'offered_program_id', 'activity_at'], 'stu_act_user_program');
            $table->index('activity_at', 'stu_act_time_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('student_activities');
    }
};
