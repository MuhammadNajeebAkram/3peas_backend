<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeacherClassAssignmentSubmissionVersion extends Model
{
    protected $table = 'teacher_class_assignment_submission_versions';

    protected $fillable = [
        'submission_id',
        'assignment_id',
        'student_web_user_id',
        'version_number',
        'text_response',
        'is_late',
        'submitted_at',
        'attachments_snapshot',
    ];

    protected $casts = [
        'version_number' => 'integer',
        'is_late' => 'boolean',
        'submitted_at' => 'datetime',
        'attachments_snapshot' => 'array',
    ];

    public function submission(): BelongsTo
    {
        return $this->belongsTo(TeacherClassAssignmentSubmission::class, 'submission_id');
    }
}
