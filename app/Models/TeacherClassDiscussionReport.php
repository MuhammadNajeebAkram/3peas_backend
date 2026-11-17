<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeacherClassDiscussionReport extends Model
{
    public const STATUS_OPEN = 'open';
    public const STATUS_REVIEWED = 'reviewed';
    public const STATUS_DISMISSED = 'dismissed';

    protected $table = 'teacher_class_discussion_reports';

    protected $fillable = [
        'reason',
    ];

    protected $casts = [
        'teacher_class_id' => 'integer',
        'discussion_id' => 'integer',
        'reply_id' => 'integer',
        'reporter_web_user_id' => 'integer',
        'reviewed_by_web_user_id' => 'integer',
        'reviewed_at' => 'datetime',
    ];

    public function discussion(): BelongsTo
    {
        return $this->belongsTo(TeacherClassDiscussion::class, 'discussion_id');
    }

    public function reply(): BelongsTo
    {
        return $this->belongsTo(TeacherClassDiscussionReply::class, 'reply_id');
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(WebUser::class, 'reporter_web_user_id');
    }
}
