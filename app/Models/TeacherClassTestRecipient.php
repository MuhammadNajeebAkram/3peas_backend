<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TeacherClassTestRecipient extends Model
{
    public const STATUS_ASSIGNED = 'assigned';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_REVOKED = 'revoked';

    protected $table = 'teacher_class_test_recipients';

    protected $fillable = [
        'assignment_status',
    ];

    protected $casts = [
        'teacher_class_test_assignment_id' => 'integer',
        'teacher_class_test_id' => 'integer',
        'teacher_class_id' => 'integer',
        'student_web_user_id' => 'integer',
        'teacher_class_enrollment_id' => 'integer',
        'assigned_at' => 'datetime',
        'notified_assigned_at' => 'datetime',
        'notified_available_at' => 'datetime',
        'notified_result_at' => 'datetime',
    ];

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(TeacherClassTestAssignment::class, 'teacher_class_test_assignment_id');
    }

    public function test(): BelongsTo
    {
        return $this->belongsTo(TeacherClassTest::class, 'teacher_class_test_id');
    }

    public function teacherClass(): BelongsTo
    {
        return $this->belongsTo(TeacherClass::class, 'teacher_class_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(WebUser::class, 'student_web_user_id');
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(TeacherClassEnrollment::class, 'teacher_class_enrollment_id');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(TeacherClassTestAttempt::class, 'teacher_class_test_recipient_id')
            ->orderBy('attempt_number');
    }
}
