<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeacherClassAssignmentRecipient extends Model
{
    protected $table = 'teacher_class_assignment_recipients';

    protected $fillable = [
        'assignment_id',
        'student_web_user_id',
        'teacher_class_enrollment_id',
        'assigned_by_web_user_id',
        'assigned_at',
        'notified_at',
    ];

    protected $casts = [
        'assigned_at' => 'datetime',
        'notified_at' => 'datetime',
    ];

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(TeacherClassAssignment::class, 'assignment_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(WebUser::class, 'student_web_user_id');
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(TeacherClassEnrollment::class, 'teacher_class_enrollment_id');
    }
}
