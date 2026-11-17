<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeacherClassEnrollmentEvent extends Model
{
    public $timestamps = false;

    protected $table = 'teacher_class_enrollment_events';

    protected $fillable = [
        'teacher_class_enrollment_id',
        'teacher_class_id',
        'student_web_user_id',
        'event_type',
        'previous_status',
        'new_status',
        'actor_web_user_id',
        'actor_role',
        'reason',
        'metadata',
        'created_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(TeacherClassEnrollment::class, 'teacher_class_enrollment_id');
    }

    public function teacherClass(): BelongsTo
    {
        return $this->belongsTo(TeacherClass::class, 'teacher_class_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(WebUser::class, 'student_web_user_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(WebUser::class, 'actor_web_user_id');
    }
}
