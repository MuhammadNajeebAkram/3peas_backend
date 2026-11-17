<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('teacher_classes') || !Schema::hasTable('teacher_profiles') || !Schema::hasTable('web_users')) {
            throw new \RuntimeException('Core teacher classes, profiles, and web_users tables are required before Phase 7F migration.');
        }

        if (!Schema::hasTable('teacher_class_assignments')) {
            Schema::create('teacher_class_assignments', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('teacher_class_id');
                $table->unsignedBigInteger('teacher_profile_id');
                $table->unsignedBigInteger('created_by_web_user_id')->nullable();
                $table->string('title', 255);
                $table->text('instructions');
                $table->unsignedBigInteger('book_unit_id')->nullable();
                $table->unsignedBigInteger('book_unit_topic_id')->nullable();
                $table->decimal('max_marks', 6, 2)->nullable();
                $table->enum('assignment_mode', ['entire_class', 'selected_students'])->default('entire_class');
                $table->enum('status', ['draft', 'scheduled', 'published', 'closed', 'archived'])->default('draft');
                $table->enum('late_policy', ['block_late', 'allow_late'])->default('allow_late');
                $table->boolean('allow_resubmission')->default(true);
                $table->timestamp('scheduled_publish_at')->nullable();
                $table->timestamp('published_at')->nullable();
                $table->timestamp('due_at')->nullable();
                $table->timestamp('closed_at')->nullable();
                $table->timestamp('archived_at')->nullable();
                $table->timestamp('notified_published_at')->nullable();
                $table->timestamp('reminded_due_at')->nullable();
                $table->timestamps();

                $table->index(['teacher_class_id', 'status', 'due_at'], 'idx_tca7f_class_status_due');
                $table->index(['status', 'scheduled_publish_at'], 'idx_tca7f_sched');

                $table->foreign('teacher_class_id', 'tca7f_class_fk')
                    ->references('id')->on('teacher_classes')->cascadeOnDelete();
                $table->foreign('teacher_profile_id', 'tca7f_profile_fk')
                    ->references('id')->on('teacher_profiles')->cascadeOnDelete();
                $table->foreign('created_by_web_user_id', 'tca7f_creator_fk')
                    ->references('id')->on('web_users')->nullOnDelete();
            });
        }

        if (!Schema::hasTable('teacher_class_assignment_attachments')) {
            Schema::create('teacher_class_assignment_attachments', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('assignment_id');
                $table->unsignedBigInteger('uploaded_by_web_user_id')->nullable();
                $table->string('original_name', 255);
                $table->string('mime_type', 120);
                $table->string('extension', 20);
                $table->unsignedBigInteger('size_bytes');
                $table->string('storage_disk', 20);
                $table->string('storage_key', 500);
                $table->timestamps();

                $table->index('assignment_id', 'idx_tcaa_assign');

                $table->foreign('assignment_id', 'tcaa_assign_fk')
                    ->references('id')->on('teacher_class_assignments')->cascadeOnDelete();
                $table->foreign('uploaded_by_web_user_id', 'tcaa_uploader_fk')
                    ->references('id')->on('web_users')->nullOnDelete();
            });
        }

        if (!Schema::hasTable('teacher_class_assignment_recipients')) {
            Schema::create('teacher_class_assignment_recipients', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('assignment_id');
                $table->unsignedBigInteger('student_web_user_id');
                $table->unsignedBigInteger('teacher_class_enrollment_id')->nullable();
                $table->unsignedBigInteger('assigned_by_web_user_id')->nullable();
                $table->timestamp('assigned_at');
                $table->timestamp('notified_at')->nullable();
                $table->timestamps();

                $table->unique(['assignment_id', 'student_web_user_id'], 'uk_tcar_assign_student');
                $table->index(['student_web_user_id', 'assignment_id'], 'idx_tcar_student_assign');

                $table->foreign('assignment_id', 'tcar7f_assign_fk')
                    ->references('id')->on('teacher_class_assignments')->cascadeOnDelete();
                $table->foreign('student_web_user_id', 'tcar7f_student_fk')
                    ->references('id')->on('web_users')->cascadeOnDelete();
                $table->foreign('assigned_by_web_user_id', 'tcar7f_assigned_by_fk')
                    ->references('id')->on('web_users')->nullOnDelete();
            });
        }

        if (!Schema::hasTable('teacher_class_assignment_submissions')) {
            Schema::create('teacher_class_assignment_submissions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('assignment_id');
                $table->unsignedBigInteger('teacher_class_id');
                $table->unsignedBigInteger('student_web_user_id');
                $table->enum('status', ['draft', 'submitted'])->default('draft');
                $table->unsignedInteger('submission_version')->default(1);
                $table->longText('text_response')->nullable();
                $table->boolean('is_late')->default(false);
                $table->timestamp('first_submitted_at')->nullable();
                $table->timestamp('submitted_at')->nullable();
                $table->timestamp('last_saved_at')->nullable();
                $table->timestamps();

                $table->unique(['assignment_id', 'student_web_user_id'], 'uk_tcas_assign_student');
                $table->index(['assignment_id', 'status', 'is_late'], 'idx_tcas_assign_status');

                $table->foreign('assignment_id', 'tcas_assign_fk')
                    ->references('id')->on('teacher_class_assignments')->cascadeOnDelete();
                $table->foreign('teacher_class_id', 'tcas_class_fk')
                    ->references('id')->on('teacher_classes')->cascadeOnDelete();
                $table->foreign('student_web_user_id', 'tcas_student_fk')
                    ->references('id')->on('web_users')->cascadeOnDelete();
            });
        }

        if (!Schema::hasTable('teacher_class_assignment_submission_versions')) {
            Schema::create('teacher_class_assignment_submission_versions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('submission_id');
                $table->unsignedBigInteger('assignment_id');
                $table->unsignedBigInteger('student_web_user_id');
                $table->unsignedInteger('version_number');
                $table->longText('text_response')->nullable();
                $table->boolean('is_late')->default(false);
                $table->timestamp('submitted_at');
                $table->json('attachments_snapshot')->nullable();
                $table->timestamps();

                $table->unique(['submission_id', 'version_number'], 'uk_tcasv_sub_ver');
                $table->index(['assignment_id', 'student_web_user_id'], 'idx_tcasv_assign_student');

                $table->foreign('submission_id', 'tcasv_submission_fk')
                    ->references('id')->on('teacher_class_assignment_submissions')->cascadeOnDelete();
                $table->foreign('assignment_id', 'tcasv_assign_fk')
                    ->references('id')->on('teacher_class_assignments')->cascadeOnDelete();
                $table->foreign('student_web_user_id', 'tcasv_student_fk')
                    ->references('id')->on('web_users')->cascadeOnDelete();
            });
        }

        if (!Schema::hasTable('teacher_class_submission_attachments')) {
            Schema::create('teacher_class_submission_attachments', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('submission_id');
                $table->unsignedInteger('version_number')->default(1);
                $table->unsignedBigInteger('uploaded_by_web_user_id')->nullable();
                $table->string('original_name', 255);
                $table->string('mime_type', 120);
                $table->string('extension', 20);
                $table->unsignedBigInteger('size_bytes');
                $table->string('storage_disk', 20);
                $table->string('storage_key', 500);
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->index(['submission_id', 'is_active', 'version_number'], 'idx_tcsa_sub_active');

                $table->foreign('submission_id', 'tcsa_sub_fk')
                    ->references('id')->on('teacher_class_assignment_submissions')->cascadeOnDelete();
                $table->foreign('uploaded_by_web_user_id', 'tcsa_uploader_fk')
                    ->references('id')->on('web_users')->nullOnDelete();
            });
        }

        if (!Schema::hasTable('teacher_class_calendar_events')) {
            Schema::create('teacher_class_calendar_events', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('teacher_class_id');
                $table->unsignedBigInteger('teacher_profile_id');
                $table->unsignedBigInteger('created_by_web_user_id')->nullable();
                $table->string('title', 255);
                $table->text('description')->nullable();
                $table->enum('event_type', [
                    'test',
                    'homework_deadline',
                    'assignment_deadline',
                    'revision_session',
                    'lesson',
                    'academic_event',
                    'other',
                ])->default('lesson');
                $table->timestamp('starts_at');
                $table->timestamp('ends_at')->nullable();
                $table->string('location_or_link', 255)->nullable();
                $table->string('attachment_name', 255)->nullable();
                $table->string('attachment_mime', 120)->nullable();
                $table->unsignedBigInteger('attachment_size')->nullable();
                $table->string('attachment_disk', 20)->nullable();
                $table->string('attachment_key', 500)->nullable();
                $table->enum('status', ['draft', 'published', 'cancelled', 'archived'])->default('published');
                $table->unsignedSmallInteger('reminder_minutes_before')->nullable();
                $table->timestamp('notified_published_at')->nullable();
                $table->timestamp('reminded_at')->nullable();
                $table->timestamps();

                $table->index(['teacher_class_id', 'status', 'starts_at'], 'idx_tcce_class_status_start');

                $table->foreign('teacher_class_id', 'tcce_class_fk')
                    ->references('id')->on('teacher_classes')->cascadeOnDelete();
                $table->foreign('teacher_profile_id', 'tcce_profile_fk')
                    ->references('id')->on('teacher_profiles')->cascadeOnDelete();
                $table->foreign('created_by_web_user_id', 'tcce_creator_fk')
                    ->references('id')->on('web_users')->nullOnDelete();
            });
        }

        if (!Schema::hasTable('teacher_class_attendance_sessions')) {
            Schema::create('teacher_class_attendance_sessions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('teacher_class_id');
                $table->unsignedBigInteger('teacher_profile_id');
                $table->unsignedBigInteger('created_by_web_user_id')->nullable();
                $table->date('session_date');
                $table->string('start_time', 5)->nullable();
                $table->string('end_time', 5)->nullable();
                $table->string('slot_key', 20)->default('full_day');
                $table->string('title', 255)->nullable();
                $table->text('notes')->nullable();
                $table->enum('status', ['open', 'finalized'])->default('open');
                $table->timestamp('finalized_at')->nullable();
                $table->unsignedBigInteger('finalized_by_web_user_id')->nullable();
                $table->timestamp('reopened_at')->nullable();
                $table->unsignedBigInteger('reopened_by_web_user_id')->nullable();
                $table->timestamp('notified_finalized_at')->nullable();
                $table->timestamps();

                $table->unique(['teacher_class_id', 'session_date', 'slot_key'], 'uk_tcas_class_date_slot');
                $table->index(['teacher_class_id', 'session_date', 'status'], 'idx_tcas_class_date_status');

                $table->foreign('teacher_class_id', 'tcats_class_fk')
                    ->references('id')->on('teacher_classes')->cascadeOnDelete();
                $table->foreign('teacher_profile_id', 'tcats_profile_fk')
                    ->references('id')->on('teacher_profiles')->cascadeOnDelete();
                $table->foreign('created_by_web_user_id', 'tcats_creator_fk')
                    ->references('id')->on('web_users')->nullOnDelete();
                $table->foreign('finalized_by_web_user_id', 'tcats_finalizer_fk')
                    ->references('id')->on('web_users')->nullOnDelete();
                $table->foreign('reopened_by_web_user_id', 'tcats_reopener_fk')
                    ->references('id')->on('web_users')->nullOnDelete();
            });
        }

        if (!Schema::hasTable('teacher_class_attendance_records')) {
            Schema::create('teacher_class_attendance_records', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('session_id');
                $table->unsignedBigInteger('teacher_class_id');
                $table->unsignedBigInteger('student_web_user_id');
                $table->unsignedBigInteger('teacher_class_enrollment_id')->nullable();
                $table->enum('status', ['present', 'absent', 'late', 'excused']);
                $table->string('remarks', 500)->nullable();
                $table->unsignedBigInteger('marked_by_web_user_id')->nullable();
                $table->timestamp('marked_at')->nullable();
                $table->timestamps();

                $table->unique(['session_id', 'student_web_user_id'], 'uk_tcar_session_student');
                $table->index(['student_web_user_id', 'teacher_class_id'], 'idx_tcatr_student_class');

                $table->foreign('session_id', 'tcatr_session_fk')
                    ->references('id')->on('teacher_class_attendance_sessions')->cascadeOnDelete();
                $table->foreign('teacher_class_id', 'tcatr_class_fk')
                    ->references('id')->on('teacher_classes')->cascadeOnDelete();
                $table->foreign('student_web_user_id', 'tcatr_student_fk')
                    ->references('id')->on('web_users')->cascadeOnDelete();
                $table->foreign('marked_by_web_user_id', 'tcatr_marker_fk')
                    ->references('id')->on('web_users')->nullOnDelete();
            });
        }

        if (!Schema::hasTable('teacher_class_attendance_audit_logs')) {
            Schema::create('teacher_class_attendance_audit_logs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('session_id');
                $table->unsignedBigInteger('record_id')->nullable();
                $table->unsignedBigInteger('student_web_user_id')->nullable();
                $table->enum('action', [
                    'session_created',
                    'session_finalized',
                    'session_reopened',
                    'record_marked',
                    'record_corrected',
                    'bulk_marked',
                ]);
                $table->string('previous_status', 20)->nullable();
                $table->string('new_status', 20)->nullable();
                $table->string('reason', 500)->nullable();
                $table->unsignedBigInteger('acted_by_web_user_id')->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->index(['session_id', 'created_at'], 'idx_tcaal_session_created');

                $table->foreign('session_id', 'tcaal_session_fk')
                    ->references('id')->on('teacher_class_attendance_sessions')->cascadeOnDelete();
                $table->foreign('record_id', 'tcaal_record_fk')
                    ->references('id')->on('teacher_class_attendance_records')->nullOnDelete();
                $table->foreign('acted_by_web_user_id', 'tcaal_actor_fk')
                    ->references('id')->on('web_users')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('teacher_class_attendance_audit_logs');
        Schema::dropIfExists('teacher_class_attendance_records');
        Schema::dropIfExists('teacher_class_attendance_sessions');
        Schema::dropIfExists('teacher_class_calendar_events');
        Schema::dropIfExists('teacher_class_submission_attachments');
        Schema::dropIfExists('teacher_class_assignment_submission_versions');
        Schema::dropIfExists('teacher_class_assignment_submissions');
        Schema::dropIfExists('teacher_class_assignment_recipients');
        Schema::dropIfExists('teacher_class_assignment_attachments');
        Schema::dropIfExists('teacher_class_assignments');
    }
};
