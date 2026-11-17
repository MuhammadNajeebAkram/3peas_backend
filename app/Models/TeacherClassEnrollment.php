<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TeacherClassEnrollment extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_REMOVED = 'removed';
    public const STATUS_LEFT = 'left';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_ACTIVE,
        self::STATUS_REJECTED,
        self::STATUS_REMOVED,
        self::STATUS_LEFT,
    ];

    protected $table = 'teacher_class_enrollments';

    protected $fillable = [
        'teacher_class_id',
        'student_web_user_id',
        'status',
        'joined_via_code',
        'requested_at',
        'approved_at',
        'rejected_at',
        'removed_at',
        'left_at',
        'reviewed_by_web_user_id',
        'removed_by_web_user_id',
        'status_reason',
    ];

    protected $casts = [
        'requested_at' => 'datetime',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'removed_at' => 'datetime',
        'left_at' => 'datetime',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function teacherClass(): BelongsTo
    {
        return $this->belongsTo(TeacherClass::class, 'teacher_class_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(WebUser::class, 'student_web_user_id');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(WebUser::class, 'reviewed_by_web_user_id');
    }

    public function removedBy(): BelongsTo
    {
        return $this->belongsTo(WebUser::class, 'removed_by_web_user_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(TeacherClassEnrollmentEvent::class, 'teacher_class_enrollment_id')
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }
}
