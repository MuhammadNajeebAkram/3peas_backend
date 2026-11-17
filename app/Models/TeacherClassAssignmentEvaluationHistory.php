<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeacherClassAssignmentEvaluationHistory extends Model
{
    public const ACTION_DRAFT_SAVED = 'draft_saved';
    public const ACTION_RELEASED = 'released';
    public const ACTION_REOPENED = 'reopened';
    public const ACTION_CORRECTED = 'corrected';
    public const ACTION_FINALIZED_ZERO = 'finalized_zero';

    public $timestamps = false;

    protected $table = 'teacher_class_assignment_evaluation_histories';

    protected $fillable = [
        'evaluation_id',
        'assignment_id',
        'teacher_class_id',
        'student_web_user_id',
        'actor_web_user_id',
        'action',
        'previous_status',
        'new_status',
        'previous_marks',
        'new_marks',
        'previous_feedback',
        'new_feedback',
        'reason',
        'created_at',
    ];

    protected $casts = [
        'previous_marks' => 'float',
        'new_marks' => 'float',
        'created_at' => 'datetime',
    ];

    public function evaluation(): BelongsTo
    {
        return $this->belongsTo(TeacherClassAssignmentEvaluation::class, 'evaluation_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(WebUser::class, 'actor_web_user_id');
    }
}
