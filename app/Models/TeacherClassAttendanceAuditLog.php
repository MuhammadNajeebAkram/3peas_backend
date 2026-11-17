<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeacherClassAttendanceAuditLog extends Model
{
    public $timestamps = false;

    protected $table = 'teacher_class_attendance_audit_logs';

    protected $fillable = [
        'session_id',
        'record_id',
        'student_web_user_id',
        'action',
        'previous_status',
        'new_status',
        'reason',
        'acted_by_web_user_id',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(TeacherClassAttendanceSession::class, 'session_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(WebUser::class, 'acted_by_web_user_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(WebUser::class, 'student_web_user_id');
    }
}
