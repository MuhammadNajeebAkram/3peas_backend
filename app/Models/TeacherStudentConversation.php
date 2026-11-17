<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TeacherStudentConversation extends Model
{
    protected $table = 'teacher_student_conversations';

    protected $fillable = [];

    protected $casts = [
        'teacher_profile_id' => 'integer',
        'teacher_web_user_id' => 'integer',
        'student_web_user_id' => 'integer',
        'context_teacher_class_id' => 'integer',
        'last_message_id' => 'integer',
        'last_message_sender_id' => 'integer',
        'last_message_at' => 'datetime',
        'teacher_last_read_at' => 'datetime',
        'student_last_read_at' => 'datetime',
    ];

    public function teacherProfile(): BelongsTo
    {
        return $this->belongsTo(TeacherProfile::class, 'teacher_profile_id');
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(WebUser::class, 'teacher_web_user_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(WebUser::class, 'student_web_user_id');
    }

    public function contextClass(): BelongsTo
    {
        return $this->belongsTo(TeacherClass::class, 'context_teacher_class_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(TeacherStudentMessage::class, 'conversation_id')
            ->orderBy('id');
    }
}
