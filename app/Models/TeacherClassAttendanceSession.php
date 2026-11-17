<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TeacherClassAttendanceSession extends Model
{
    public const STATUS_OPEN = 'open';
    public const STATUS_FINALIZED = 'finalized';

    protected $table = 'teacher_class_attendance_sessions';

    protected $fillable = [
        'teacher_class_id',
        'teacher_profile_id',
        'created_by_web_user_id',
        'session_date',
        'start_time',
        'end_time',
        'slot_key',
        'title',
        'notes',
        'status',
        'finalized_at',
        'finalized_by_web_user_id',
        'reopened_at',
        'reopened_by_web_user_id',
        'notified_finalized_at',
    ];

    protected $casts = [
        'session_date' => 'date:Y-m-d',
        'finalized_at' => 'datetime',
        'reopened_at' => 'datetime',
        'notified_finalized_at' => 'datetime',
    ];

    public function teacherClass(): BelongsTo
    {
        return $this->belongsTo(TeacherClass::class, 'teacher_class_id');
    }

    public function teacherProfile(): BelongsTo
    {
        return $this->belongsTo(TeacherProfile::class, 'teacher_profile_id');
    }

    public function records(): HasMany
    {
        return $this->hasMany(TeacherClassAttendanceRecord::class, 'session_id')->orderBy('id');
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(TeacherClassAttendanceAuditLog::class, 'session_id')->orderByDesc('id');
    }
}
