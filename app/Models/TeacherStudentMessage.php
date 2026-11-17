<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeacherStudentMessage extends Model
{
    protected $table = 'teacher_student_messages';

    protected $fillable = [
        'body',
    ];

    protected $casts = [
        'conversation_id' => 'integer',
        'sender_web_user_id' => 'integer',
        'read_at' => 'datetime',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(TeacherStudentConversation::class, 'conversation_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(WebUser::class, 'sender_web_user_id');
    }
}
