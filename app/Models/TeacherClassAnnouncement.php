<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TeacherClassAnnouncement extends Model
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_ARCHIVED = 'archived';

    protected $table = 'teacher_class_announcements';

    protected $fillable = [
        'title',
        'body',
        'scheduled_at',
        'is_pinned',
    ];

    protected $casts = [
        'teacher_class_id' => 'integer',
        'teacher_profile_id' => 'integer',
        'created_by_web_user_id' => 'integer',
        'is_pinned' => 'boolean',
        'pinned_at' => 'datetime',
        'scheduled_at' => 'datetime',
        'published_at' => 'datetime',
        'archived_at' => 'datetime',
        'notified_at' => 'datetime',
    ];

    public function scopeForClass(Builder $query, TeacherClass $class): Builder
    {
        return $query
            ->where('teacher_class_id', $class->id)
            ->where('teacher_profile_id', $class->teacher_profile_id);
    }

    public function teacherClass(): BelongsTo
    {
        return $this->belongsTo(TeacherClass::class, 'teacher_class_id');
    }

    public function teacherProfile(): BelongsTo
    {
        return $this->belongsTo(TeacherProfile::class, 'teacher_profile_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(WebUser::class, 'created_by_web_user_id');
    }

    public function reads(): HasMany
    {
        return $this->hasMany(TeacherClassAnnouncementRead::class, 'announcement_id');
    }
}
