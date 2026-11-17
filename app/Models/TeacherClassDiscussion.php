<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TeacherClassDiscussion extends Model
{
    public const STATUS_OPEN = 'open';
    public const STATUS_CLOSED = 'closed';
    public const STATUS_HIDDEN = 'hidden';
    public const STATUS_DELETED = 'deleted';

    protected $table = 'teacher_class_discussions';

    protected $fillable = [
        'title',
        'body',
    ];

    protected $casts = [
        'teacher_class_id' => 'integer',
        'author_web_user_id' => 'integer',
        'is_pinned' => 'boolean',
        'pinned_at' => 'datetime',
        'closed_at' => 'datetime',
        'closed_by_web_user_id' => 'integer',
        'moderated_at' => 'datetime',
        'moderated_by_web_user_id' => 'integer',
        'deleted_at' => 'datetime',
        'replies_count' => 'integer',
        'last_activity_at' => 'datetime',
    ];

    public function teacherClass(): BelongsTo
    {
        return $this->belongsTo(TeacherClass::class, 'teacher_class_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(WebUser::class, 'author_web_user_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(TeacherClassDiscussionReply::class, 'discussion_id')
            ->orderBy('created_at')
            ->orderBy('id');
    }

    public function reports(): HasMany
    {
        return $this->hasMany(TeacherClassDiscussionReport::class, 'discussion_id');
    }
}
