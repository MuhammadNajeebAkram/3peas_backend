<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TeacherClassAssignmentEvaluation extends Model
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_RELEASED = 'released';
    public const STATUS_REOPENED = 'reopened';

    public const TYPE_GRADED = 'graded';
    public const TYPE_FINALIZED_ZERO = 'finalized_zero';

    protected $table = 'teacher_class_assignment_evaluations';

    protected $fillable = [
        'assignment_id',
        'teacher_class_id',
        'submission_id',
        'student_web_user_id',
        'evaluated_by_web_user_id',
        'status',
        'evaluation_type',
        'obtained_marks',
        'max_marks_snapshot',
        'feedback_text',
        'last_correction_reason',
        'submission_version_evaluated',
        'draft_saved_at',
        'released_at',
        'reopened_at',
        'notified_released_at',
    ];

    protected $casts = [
        'obtained_marks' => 'float',
        'max_marks_snapshot' => 'float',
        'submission_version_evaluated' => 'integer',
        'draft_saved_at' => 'datetime',
        'released_at' => 'datetime',
        'reopened_at' => 'datetime',
        'notified_released_at' => 'datetime',
    ];

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(TeacherClassAssignment::class, 'assignment_id');
    }

    public function teacherClass(): BelongsTo
    {
        return $this->belongsTo(TeacherClass::class, 'teacher_class_id');
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(TeacherClassAssignmentSubmission::class, 'submission_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(WebUser::class, 'student_web_user_id');
    }

    public function evaluator(): BelongsTo
    {
        return $this->belongsTo(WebUser::class, 'evaluated_by_web_user_id');
    }

    public function histories(): HasMany
    {
        return $this->hasMany(TeacherClassAssignmentEvaluationHistory::class, 'evaluation_id')
            ->orderByDesc('id');
    }

    public function isReleasedToStudent(): bool
    {
        return $this->status === self::STATUS_RELEASED;
    }
}
