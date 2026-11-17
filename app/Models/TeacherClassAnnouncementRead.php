<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeacherClassAnnouncementRead extends Model
{
    protected $table = 'teacher_class_announcement_reads';

    protected $fillable = [
        'announcement_id',
        'student_web_user_id',
        'read_at',
    ];

    protected $casts = [
        'announcement_id' => 'integer',
        'student_web_user_id' => 'integer',
        'read_at' => 'datetime',
    ];

    public function announcement(): BelongsTo
    {
        return $this->belongsTo(TeacherClassAnnouncement::class, 'announcement_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(WebUser::class, 'student_web_user_id');
    }
}
