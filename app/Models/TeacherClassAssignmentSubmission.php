<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TeacherClassAssignmentSubmission extends Model
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_SUBMITTED = 'submitted';

    protected $table = 'teacher_class_assignment_submissions';

    protected $fillable = [
        'assignment_id',
        'teacher_class_id',
        'student_web_user_id',
        'status',
        'submission_version',
        'text_response',
        'is_late',
        'first_submitted_at',
        'submitted_at',
        'last_saved_at',
    ];

    protected $casts = [
        'submission_version' => 'integer',
        'is_late' => 'boolean',
        'first_submitted_at' => 'datetime',
        'submitted_at' => 'datetime',
        'last_saved_at' => 'datetime',
    ];

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(TeacherClassAssignment::class, 'assignment_id');
    }

    public function teacherClass(): BelongsTo
    {
        return $this->belongsTo(TeacherClass::class, 'teacher_class_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(WebUser::class, 'student_web_user_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(TeacherClassAssignmentSubmissionVersion::class, 'submission_id')->orderByDesc('version_number');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(TeacherClassSubmissionAttachment::class, 'submission_id')->orderBy('id');
    }

    public function activeAttachments(): HasMany
    {
        return $this->hasMany(TeacherClassSubmissionAttachment::class, 'submission_id')
            ->where('is_active', true)
            ->orderBy('id');
    }

    public function evaluation(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(TeacherClassAssignmentEvaluation::class, 'submission_id');
    }
}
