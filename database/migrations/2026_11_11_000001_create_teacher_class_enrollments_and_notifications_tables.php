<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('teacher_classes')) {
            throw new \RuntimeException('teacher_classes is required before creating class enrollment tables.');
        }
        if (!Schema::hasTable('web_users')) {
            throw new \RuntimeException('web_users is required before creating class enrollment tables.');
        }

        if (!Schema::hasTable('teacher_class_enrollments')) {
            Schema::create('teacher_class_enrollments', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('teacher_class_id');
                $table->unsignedBigInteger('student_web_user_id');
                $table->string('status', 20);
                $table->string('joined_via_code', 16)->nullable();
                $table->timestamp('requested_at')->nullable();
                $table->timestamp('approved_at')->nullable();
                $table->timestamp('rejected_at')->nullable();
                $table->timestamp('removed_at')->nullable();
                $table->timestamp('left_at')->nullable();
                $table->unsignedBigInteger('reviewed_by_web_user_id')->nullable();
                $table->unsignedBigInteger('removed_by_web_user_id')->nullable();
                $table->string('status_reason', 1000)->nullable();
                $table->timestamps();

                $table->unique(['teacher_class_id', 'student_web_user_id'], 'uk_teacher_class_student');
                $table->index(['teacher_class_id', 'status', 'created_at'], 'idx_tce_class_status_created');
                $table->index(['student_web_user_id', 'status', 'created_at'], 'idx_tce_student_status_created');

                $table->foreign('teacher_class_id', 'tce_class_fk')
                    ->references('id')->on('teacher_classes')->cascadeOnDelete();
                $table->foreign('student_web_user_id', 'tce_student_fk')
                    ->references('id')->on('web_users')->cascadeOnDelete();
                $table->foreign('reviewed_by_web_user_id', 'tce_reviewed_by_fk')
                    ->references('id')->on('web_users')->nullOnDelete();
                $table->foreign('removed_by_web_user_id', 'tce_removed_by_fk')
                    ->references('id')->on('web_users')->nullOnDelete();
            });
        }

        if (!Schema::hasTable('teacher_class_enrollment_events')) {
            Schema::create('teacher_class_enrollment_events', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('teacher_class_enrollment_id');
                $table->unsignedBigInteger('teacher_class_id');
                $table->unsignedBigInteger('student_web_user_id');
                $table->string('event_type', 32);
                $table->string('previous_status', 20)->nullable();
                $table->string('new_status', 20);
                $table->unsignedBigInteger('actor_web_user_id')->nullable();
                $table->string('actor_role', 20);
                $table->string('reason', 1000)->nullable();
                $table->json('metadata')->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->index(['teacher_class_enrollment_id', 'created_at'], 'idx_tcee_enrollment_created');
                $table->index(['teacher_class_id', 'created_at'], 'idx_tcee_class_created');

                $table->foreign('teacher_class_enrollment_id', 'tcee_enrollment_fk')
                    ->references('id')->on('teacher_class_enrollments')->cascadeOnDelete();
                $table->foreign('teacher_class_id', 'tcee_class_fk')
                    ->references('id')->on('teacher_classes')->cascadeOnDelete();
                $table->foreign('student_web_user_id', 'tcee_student_fk')
                    ->references('id')->on('web_users')->cascadeOnDelete();
                $table->foreign('actor_web_user_id', 'tcee_actor_fk')
                    ->references('id')->on('web_users')->nullOnDelete();
            });
        }

        if (!Schema::hasTable('web_user_notifications')) {
            Schema::create('web_user_notifications', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('recipient_web_user_id');
                $table->unsignedBigInteger('actor_web_user_id')->nullable();
                $table->unsignedBigInteger('teacher_class_id')->nullable();
                $table->unsignedBigInteger('teacher_class_enrollment_id')->nullable();
                $table->string('type', 64);
                $table->string('title', 255);
                $table->text('message');
                $table->string('action_url', 255)->nullable();
                $table->json('metadata')->nullable();
                $table->boolean('is_read')->default(false);
                $table->timestamp('read_at')->nullable();
                $table->timestamps();

                $table->index(['recipient_web_user_id', 'is_read', 'created_at'], 'idx_wun_recipient_read_created');

                $table->foreign('recipient_web_user_id', 'wun_recipient_fk')
                    ->references('id')->on('web_users')->cascadeOnDelete();
                $table->foreign('actor_web_user_id', 'wun_actor_fk')
                    ->references('id')->on('web_users')->nullOnDelete();
                $table->foreign('teacher_class_id', 'wun_class_fk')
                    ->references('id')->on('teacher_classes')->cascadeOnDelete();
                $table->foreign('teacher_class_enrollment_id', 'wun_enrollment_fk')
                    ->references('id')->on('teacher_class_enrollments')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('web_user_notifications');
        Schema::dropIfExists('teacher_class_enrollment_events');
        Schema::dropIfExists('teacher_class_enrollments');
    }
};
