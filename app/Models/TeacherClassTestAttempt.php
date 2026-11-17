<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TeacherClassTestAttempt extends Model
{
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_AUTO_SUBMITTED = 'auto_submitted';
    public const STATUS_EXPIRED = 'expired';

    public const REASON_MANUAL = 'manual';
    public const REASON_DEADLINE_EXPIRED = 'deadline_expired';
    public const REASON_WINDOW_CLOSED = 'window_closed';

    protected $table = 'teacher_class_test_attempts';

    protected $fillable = [];

    protected $casts = [
        'teacher_class_test_assignment_id' => 'integer',
        'teacher_class_test_recipient_id' => 'integer',
        'teacher_class_test_id' => 'integer',
        'teacher_class_id' => 'integer',
        'student_web_user_id' => 'integer',
        'attempt_number' => 'integer',
        'started_at' => 'datetime',
        'deadline_at' => 'datetime',
        'submitted_at' => 'datetime',
        'finalized_at' => 'datetime',
        'total_questions' => 'integer',
        'answered_questions' => 'integer',
        'correct_count' => 'integer',
        'incorrect_count' => 'integer',
        'unanswered_count' => 'integer',
        'max_marks' => 'decimal:2',
        'obtained_marks' => 'decimal:2',
        'percentage' => 'decimal:2',
        'time_spent_seconds' => 'integer',
    ];

    public function isInProgress(): bool
    {
        return $this->status === self::STATUS_IN_PROGRESS;
    }

    public function isFinalized(): bool
    {
        return in_array($this->status, [
            self::STATUS_SUBMITTED,
            self::STATUS_AUTO_SUBMITTED,
            self::STATUS_EXPIRED,
        ], true);
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(TeacherClassTestAssignment::class, 'teacher_class_test_assignment_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(TeacherClassTestRecipient::class, 'teacher_class_test_recipient_id');
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

    public function answers(): HasMany
    {
        return $this->hasMany(TeacherClassTestAttemptAnswer::class, 'attempt_id')
            ->orderBy('question_order')
            ->orderBy('id');
    }
}
