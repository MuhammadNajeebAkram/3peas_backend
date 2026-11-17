<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeacherClassCalendarEvent extends Model
{
    public const TYPE_TEST = 'test';
    public const TYPE_HOMEWORK_DEADLINE = 'homework_deadline';
    public const TYPE_ASSIGNMENT_DEADLINE = 'assignment_deadline';
    public const TYPE_REVISION_SESSION = 'revision_session';
    public const TYPE_LESSON = 'lesson';
    public const TYPE_ACADEMIC_EVENT = 'academic_event';
    public const TYPE_OTHER = 'other';

    public const TYPES = [
        self::TYPE_TEST,
        self::TYPE_HOMEWORK_DEADLINE,
        self::TYPE_ASSIGNMENT_DEADLINE,
        self::TYPE_REVISION_SESSION,
        self::TYPE_LESSON,
        self::TYPE_ACADEMIC_EVENT,
        self::TYPE_OTHER,
    ];

    public const STATUS_DRAFT = 'draft';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_ARCHIVED = 'archived';

    protected $table = 'teacher_class_calendar_events';

    protected $fillable = [
        'teacher_class_id',
        'teacher_profile_id',
        'created_by_web_user_id',
        'title',
        'description',
        'event_type',
        'starts_at',
        'ends_at',
        'location_or_link',
        'attachment_name',
        'attachment_mime',
        'attachment_size',
        'attachment_disk',
        'attachment_key',
        'status',
        'reminder_minutes_before',
        'notified_published_at',
        'reminded_at',
    ];

    protected $hidden = [
        'attachment_disk',
        'attachment_key',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'attachment_size' => 'integer',
        'reminder_minutes_before' => 'integer',
        'notified_published_at' => 'datetime',
        'reminded_at' => 'datetime',
    ];

    public function teacherClass(): BelongsTo
    {
        return $this->belongsTo(TeacherClass::class, 'teacher_class_id');
    }

    public function teacherProfile(): BelongsTo
    {
        return $this->belongsTo(TeacherProfile::class, 'teacher_profile_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(WebUser::class, 'created_by_web_user_id');
    }
}
