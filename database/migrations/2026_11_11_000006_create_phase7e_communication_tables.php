<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('teacher_classes') || !Schema::hasTable('teacher_profiles') || !Schema::hasTable('web_users')) {
            throw new \RuntimeException('Core teacher classes, profiles, and web_users tables are required before Phase 7E migration.');
        }

        if (!Schema::hasTable('teacher_class_announcements')) {
            Schema::create('teacher_class_announcements', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('teacher_class_id');
                $table->unsignedBigInteger('teacher_profile_id');
                $table->unsignedBigInteger('created_by_web_user_id')->nullable();
                $table->string('title', 255);
                $table->text('body');
                $table->enum('status', ['draft', 'scheduled', 'published', 'archived'])->default('draft');
                $table->boolean('is_pinned')->default(false);
                $table->timestamp('pinned_at')->nullable();
                $table->timestamp('scheduled_at')->nullable();
                $table->timestamp('published_at')->nullable();
                $table->timestamp('archived_at')->nullable();
                $table->timestamp('notified_at')->nullable();
                $table->timestamps();

                $table->index(['teacher_class_id', 'status', 'is_pinned', 'published_at'], 'idx_tca_class_status_pub');
                $table->index(['status', 'scheduled_at'], 'idx_tca_scheduled');

                $table->foreign('teacher_class_id', 'tca_class_fk')
                    ->references('id')->on('teacher_classes')->cascadeOnDelete();
                $table->foreign('teacher_profile_id', 'tca_profile_fk')
                    ->references('id')->on('teacher_profiles')->cascadeOnDelete();
                $table->foreign('created_by_web_user_id', 'tca_creator_fk')
                    ->references('id')->on('web_users')->nullOnDelete();
            });
        }

        if (!Schema::hasTable('teacher_class_announcement_reads')) {
            Schema::create('teacher_class_announcement_reads', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('announcement_id');
                $table->unsignedBigInteger('student_web_user_id');
                $table->timestamp('read_at');
                $table->timestamps();

                $table->unique(['announcement_id', 'student_web_user_id'], 'uk_tcar_announcement_student');
                $table->index(['student_web_user_id', 'read_at'], 'idx_tcar_student_read');

                $table->foreign('announcement_id', 'tcar_announcement_fk')
                    ->references('id')->on('teacher_class_announcements')->cascadeOnDelete();
                $table->foreign('student_web_user_id', 'tcar_student_fk')
                    ->references('id')->on('web_users')->cascadeOnDelete();
            });
        }

        if (!Schema::hasTable('teacher_class_discussions')) {
            Schema::create('teacher_class_discussions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('teacher_class_id');
                $table->unsignedBigInteger('author_web_user_id');
                $table->string('author_role', 20);
                $table->string('title', 255);
                $table->text('body');
                $table->enum('status', ['open', 'closed', 'hidden', 'deleted'])->default('open');
                $table->boolean('is_pinned')->default(false);
                $table->timestamp('pinned_at')->nullable();
                $table->timestamp('closed_at')->nullable();
                $table->unsignedBigInteger('closed_by_web_user_id')->nullable();
                $table->timestamp('moderated_at')->nullable();
                $table->unsignedBigInteger('moderated_by_web_user_id')->nullable();
                $table->string('moderation_reason', 500)->nullable();
                $table->timestamp('deleted_at')->nullable();
                $table->unsignedInteger('replies_count')->default(0);
                $table->timestamp('last_activity_at')->nullable();
                $table->timestamps();

                $table->index(['teacher_class_id', 'status', 'is_pinned', 'last_activity_at'], 'idx_tcd_class_status_pin');
                $table->index(['author_web_user_id', 'teacher_class_id'], 'idx_tcd_author_class');

                $table->foreign('teacher_class_id', 'tcd_class_fk')
                    ->references('id')->on('teacher_classes')->cascadeOnDelete();
                $table->foreign('author_web_user_id', 'tcd_author_fk')
                    ->references('id')->on('web_users')->cascadeOnDelete();
                $table->foreign('closed_by_web_user_id', 'tcd_closed_by_fk')
                    ->references('id')->on('web_users')->nullOnDelete();
                $table->foreign('moderated_by_web_user_id', 'tcd_moderated_by_fk')
                    ->references('id')->on('web_users')->nullOnDelete();
            });
        }

        if (!Schema::hasTable('teacher_class_discussion_replies')) {
            Schema::create('teacher_class_discussion_replies', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('discussion_id');
                $table->unsignedBigInteger('author_web_user_id');
                $table->string('author_role', 20);
                $table->text('body');
                $table->enum('status', ['visible', 'hidden', 'deleted'])->default('visible');
                $table->timestamp('moderated_at')->nullable();
                $table->unsignedBigInteger('moderated_by_web_user_id')->nullable();
                $table->string('moderation_reason', 500)->nullable();
                $table->timestamp('deleted_at')->nullable();
                $table->timestamps();

                $table->index(['discussion_id', 'status', 'created_at'], 'idx_tcdr_disc_status_created');

                $table->foreign('discussion_id', 'tcdr_discussion_fk')
                    ->references('id')->on('teacher_class_discussions')->cascadeOnDelete();
                $table->foreign('author_web_user_id', 'tcdr_author_fk')
                    ->references('id')->on('web_users')->cascadeOnDelete();
                $table->foreign('moderated_by_web_user_id', 'tcdr_moderated_by_fk')
                    ->references('id')->on('web_users')->nullOnDelete();
            });
        }

        if (!Schema::hasTable('teacher_class_discussion_reports')) {
            Schema::create('teacher_class_discussion_reports', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('teacher_class_id');
                $table->unsignedBigInteger('discussion_id');
                $table->unsignedBigInteger('reply_id')->nullable();
                $table->unsignedBigInteger('reporter_web_user_id');
                $table->string('reason', 500);
                $table->enum('status', ['open', 'reviewed', 'dismissed'])->default('open');
                $table->unsignedBigInteger('reviewed_by_web_user_id')->nullable();
                $table->timestamp('reviewed_at')->nullable();
                $table->timestamps();

                $table->index(['teacher_class_id', 'status'], 'idx_tcdrep_class_status');
                $table->index(['reporter_web_user_id', 'discussion_id', 'reply_id'], 'idx_tcdrep_reporter_target');

                $table->foreign('teacher_class_id', 'tcdrep_class_fk')
                    ->references('id')->on('teacher_classes')->cascadeOnDelete();
                $table->foreign('discussion_id', 'tcdrep_discussion_fk')
                    ->references('id')->on('teacher_class_discussions')->cascadeOnDelete();
                $table->foreign('reply_id', 'tcdrep_reply_fk')
                    ->references('id')->on('teacher_class_discussion_replies')->cascadeOnDelete();
                $table->foreign('reporter_web_user_id', 'tcdrep_reporter_fk')
                    ->references('id')->on('web_users')->cascadeOnDelete();
                $table->foreign('reviewed_by_web_user_id', 'tcdrep_reviewer_fk')
                    ->references('id')->on('web_users')->nullOnDelete();
            });
        }

        if (!Schema::hasTable('teacher_student_conversations')) {
            Schema::create('teacher_student_conversations', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('teacher_profile_id');
                $table->unsignedBigInteger('teacher_web_user_id');
                $table->unsignedBigInteger('student_web_user_id');
                $table->unsignedBigInteger('context_teacher_class_id')->nullable();
                $table->unsignedBigInteger('last_message_id')->nullable();
                $table->string('last_message_preview', 200)->nullable();
                $table->unsignedBigInteger('last_message_sender_id')->nullable();
                $table->timestamp('last_message_at')->nullable();
                $table->timestamp('teacher_last_read_at')->nullable();
                $table->timestamp('student_last_read_at')->nullable();
                $table->timestamps();

                // Enforce one canonical 1:1 conversation per teacher-student pair across shared classes
                $table->unique(['teacher_profile_id', 'student_web_user_id'], 'uk_tsc_teacher_student');
                $table->index(['teacher_profile_id', 'last_message_at'], 'idx_tsc_teacher_last');
                $table->index(['student_web_user_id', 'last_message_at'], 'idx_tsc_student_last');

                $table->foreign('teacher_profile_id', 'tsc_profile_fk')
                    ->references('id')->on('teacher_profiles')->cascadeOnDelete();
                $table->foreign('teacher_web_user_id', 'tsc_teacher_user_fk')
                    ->references('id')->on('web_users')->cascadeOnDelete();
                $table->foreign('student_web_user_id', 'tsc_student_user_fk')
                    ->references('id')->on('web_users')->cascadeOnDelete();
                $table->foreign('context_teacher_class_id', 'tsc_class_fk')
                    ->references('id')->on('teacher_classes')->nullOnDelete();
            });
        }

        if (!Schema::hasTable('teacher_student_messages')) {
            Schema::create('teacher_student_messages', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('conversation_id');
                $table->unsignedBigInteger('sender_web_user_id');
                $table->string('sender_role', 20);
                $table->text('body');
                $table->timestamp('read_at')->nullable();
                $table->timestamps();

                $table->index(['conversation_id', 'id'], 'idx_tsm_conv_id');
                $table->index(['conversation_id', 'sender_web_user_id', 'read_at'], 'idx_tsm_conv_unread');

                $table->foreign('conversation_id', 'tsm_conversation_fk')
                    ->references('id')->on('teacher_student_conversations')->cascadeOnDelete();
                $table->foreign('sender_web_user_id', 'tsm_sender_fk')
                    ->references('id')->on('web_users')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('teacher_student_messages');
        Schema::dropIfExists('teacher_student_conversations');
        Schema::dropIfExists('teacher_class_discussion_reports');
        Schema::dropIfExists('teacher_class_discussion_replies');
        Schema::dropIfExists('teacher_class_discussions');
        Schema::dropIfExists('teacher_class_announcement_reads');
        Schema::dropIfExists('teacher_class_announcements');
    }
};
