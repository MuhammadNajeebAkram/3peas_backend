<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teacher_classes', function (Blueprint $table) {
            $table->id();
            // Ownership is always derived server-side from the authenticated teacher profile.
            $table->foreignId('teacher_profile_id')->constrained('teacher_profiles')->restrictOnDelete();
            $table->foreignId('class_id')->constrained('class_tbl')->restrictOnDelete();
            $table->foreignId('subject_id')->constrained('subject_tbl')->restrictOnDelete();
            $table->foreignId('curriculum_board_id')->constrained('curriculum_board_tbl')->restrictOnDelete();
            $table->string('name', 120);
            // Empty string (not NULL) means "no section" so the duplicate guard below also covers it.
            $table->string('section', 20)->default('');
            $table->string('class_code', 16)->unique();
            $table->boolean('is_active')->default(true);
            $table->boolean('requires_approval')->default(true);
            $table->timestamps();

            $table->index(['teacher_profile_id', 'is_active'], 'teacher_classes_teacher_active_idx');
            $table->unique(
                ['teacher_profile_id', 'class_id', 'subject_id', 'curriculum_board_id', 'section'],
                'teacher_classes_unique_offering'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teacher_classes');
    }
};
