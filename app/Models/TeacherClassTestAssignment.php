<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TeacherClassTestAssignment extends Model
{
    public const MODE_ENTIRE_CLASS = 'entire_class';
    public const MODE_SELECTED_STUDENTS = 'selected_students';

    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_CLOSED = 'closed';
    public const STATUS_CANCELLED = 'cancelled';

    protected $table = 'teacher_class_test_assignments';

    protected $fillable = [
        'assignment_mode',
        'title_snapshot',
        'instructions_snapshot',
        'starts_at',
        'ends_at',
        'duration_minutes',
        'max_attempts',
        'result_visibility',
        'allow_answer_review',
    ];

    protected $casts = [
        'teacher_class_test_id' => 'integer',
        'teacher_class_id' => 'integer',
        'teacher_profile_id' => 'integer',
        'test_version' => 'integer',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'duration_minutes' => 'integer',
        'max_attempts' => 'integer',
        'allow_answer_review' => 'boolean',
        'results_released_at' => 'datetime',
        'assigned_count' => 'integer',
        'assigned_by_web_user_id' => 'integer',
        'assigned_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function scopeForClass(Builder $query, TeacherClass $class): Builder
    {
        return $query
            ->where('teacher_class_id', $class->id)
            ->where('teacher_profile_id', $class->teacher_profile_id);
    }

    public function test(): BelongsTo
    {
        return $this->belongsTo(TeacherClassTest::class, 'teacher_class_test_id');
    }

    public function teacherClass(): BelongsTo
    {
        return $this->belongsTo(TeacherClass::class, 'teacher_class_id');
    }

    public function teacherProfile(): BelongsTo
    {
        return $this->belongsTo(TeacherProfile::class, 'teacher_profile_id');
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(WebUser::class, 'assigned_by_web_user_id');
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(TeacherClassTestRecipient::class, 'teacher_class_test_assignment_id');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(TeacherClassTestAttempt::class, 'teacher_class_test_assignment_id');
    }
}
