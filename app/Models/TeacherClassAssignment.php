<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TeacherClassAssignment extends Model
{
    public const MODE_ENTIRE_CLASS = 'entire_class';
    public const MODE_SELECTED_STUDENTS = 'selected_students';

    public const STATUS_DRAFT = 'draft';
    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_CLOSED = 'closed';
    public const STATUS_ARCHIVED = 'archived';

    public const LATE_ALLOW = 'allow_late';
    public const LATE_BLOCK = 'block_late';

    protected $table = 'teacher_class_assignments';

    protected $fillable = [
        'teacher_class_id',
        'teacher_profile_id',
        'created_by_web_user_id',
        'title',
        'instructions',
        'book_unit_id',
        'book_unit_topic_id',
        'max_marks',
        'assignment_mode',
        'status',
        'late_policy',
        'allow_resubmission',
        'scheduled_publish_at',
        'published_at',
        'due_at',
        'closed_at',
        'archived_at',
        'notified_published_at',
        'reminded_due_at',
    ];

    protected $casts = [
        'book_unit_id' => 'integer',
        'book_unit_topic_id' => 'integer',
        'max_marks' => 'float',
        'allow_resubmission' => 'boolean',
        'scheduled_publish_at' => 'datetime',
        'published_at' => 'datetime',
        'due_at' => 'datetime',
        'closed_at' => 'datetime',
        'archived_at' => 'datetime',
        'notified_published_at' => 'datetime',
        'reminded_due_at' => 'datetime',
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

    public function attachments(): HasMany
    {
        return $this->hasMany(TeacherClassAssignmentAttachment::class, 'assignment_id')->orderBy('id');
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(TeacherClassAssignmentRecipient::class, 'assignment_id')->orderBy('id');
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(TeacherClassAssignmentSubmission::class, 'assignment_id')->orderByDesc('updated_at');
    }

    public function evaluations(): HasMany
    {
        return $this->hasMany(TeacherClassAssignmentEvaluation::class, 'assignment_id')->orderBy('id');
    }
}
