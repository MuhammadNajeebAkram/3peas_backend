<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeacherClassAttendanceRecord extends Model
{
    public const STATUS_PRESENT = 'present';
    public const STATUS_ABSENT = 'absent';
    public const STATUS_LATE = 'late';
    public const STATUS_EXCUSED = 'excused';

    public const STATUSES = [
        self::STATUS_PRESENT,
        self::STATUS_ABSENT,
        self::STATUS_LATE,
        self::STATUS_EXCUSED,
    ];

    protected $table = 'teacher_class_attendance_records';

    protected $fillable = [
        'session_id',
        'teacher_class_id',
        'student_web_user_id',
        'teacher_class_enrollment_id',
        'status',
        'remarks',
        'marked_by_web_user_id',
        'marked_at',
    ];

    protected $casts = [
        'marked_at' => 'datetime',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(TeacherClassAttendanceSession::class, 'session_id');
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
}
