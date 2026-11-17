<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TeacherClassDiscussionReply extends Model
{
    public const STATUS_VISIBLE = 'visible';
    public const STATUS_HIDDEN = 'hidden';
    public const STATUS_DELETED = 'deleted';

    protected $table = 'teacher_class_discussion_replies';

    protected $fillable = [
        'body',
    ];

    protected $casts = [
        'discussion_id' => 'integer',
        'author_web_user_id' => 'integer',
        'moderated_at' => 'datetime',
        'moderated_by_web_user_id' => 'integer',
        'deleted_at' => 'datetime',
    ];

    public function discussion(): BelongsTo
    {
        return $this->belongsTo(TeacherClassDiscussion::class, 'discussion_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(WebUser::class, 'author_web_user_id');
    }

    public function reports(): HasMany
    {
        return $this->hasMany(TeacherClassDiscussionReport::class, 'reply_id');
    }
}
