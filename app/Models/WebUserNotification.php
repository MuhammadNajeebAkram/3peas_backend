<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebUserNotification extends Model
{
    public const TYPE_ENROLLMENT_REQUESTED = 'enrollment_requested';
    public const TYPE_STUDENT_ENROLLED = 'student_enrolled';
    public const TYPE_ENROLLMENT_APPROVED = 'enrollment_approved';
    public const TYPE_ENROLLMENT_REJECTED = 'enrollment_rejected';
    public const TYPE_ENROLLMENT_REMOVED = 'enrollment_removed';
    public const TYPE_ENROLLMENT_LEFT = 'enrollment_left';
    public const TYPE_TEST_ASSIGNED = 'test_assigned';
    public const TYPE_TEST_AVAILABLE = 'test_available';
    public const TYPE_TEST_RESULT_RELEASED = 'test_result_released';
    public const TYPE_CLASS_ANNOUNCEMENT = 'class_announcement';
    public const TYPE_DISCUSSION_TOPIC = 'discussion_topic';
    public const TYPE_DISCUSSION_REPLY = 'discussion_reply';
    public const TYPE_DISCUSSION_REPORTED = 'discussion_reported';
    public const TYPE_PRIVATE_MESSAGE = 'private_message';
    public const TYPE_ASSIGNMENT_PUBLISHED = 'assignment_published';
    public const TYPE_ASSIGNMENT_DUE_REMINDER = 'assignment_due_reminder';
    public const TYPE_ASSIGNMENT_SUBMITTED = 'assignment_submitted';
    public const TYPE_ASSIGNMENT_CLOSED = 'assignment_closed';
    public const TYPE_ASSIGNMENT_REOPENED = 'assignment_reopened';
    public const TYPE_ASSIGNMENT_EVALUATION_RELEASED = 'assignment_evaluation_released';
    public const TYPE_ASSIGNMENT_EVALUATION_CORRECTED = 'assignment_evaluation_corrected';
    public const TYPE_CALENDAR_EVENT = 'calendar_event';
    public const TYPE_CALENDAR_REMINDER = 'calendar_reminder';
    public const TYPE_ATTENDANCE_FINALIZED = 'attendance_finalized';

    protected $table = 'web_user_notifications';

    protected $fillable = [
        'recipient_web_user_id',
        'actor_web_user_id',
        'teacher_class_id',
        'teacher_class_enrollment_id',
        'type',
        'title',
        'message',
        'action_url',
        'metadata',
        'is_read',
        'read_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'is_read' => 'boolean',
        'read_at' => 'datetime',
    ];

    public function scopeUnread(Builder $query): Builder
    {
        return $query->where('is_read', false);
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(WebUser::class, 'recipient_web_user_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(WebUser::class, 'actor_web_user_id');
    }

    public function teacherClass(): BelongsTo
    {
        return $this->belongsTo(TeacherClass::class, 'teacher_class_id');
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(TeacherClassEnrollment::class, 'teacher_class_enrollment_id');
    }
}
