<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TeacherClassTest extends Model
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_CLOSED = 'closed';
    public const STATUS_ARCHIVED = 'archived';

    public const VISIBILITY_AFTER_SUBMISSION = 'after_submission';
    public const VISIBILITY_AFTER_CLOSE = 'after_close';
    public const VISIBILITY_MANUAL_RELEASE = 'manual_release';

    public const SELECTION_MANUAL = 'manual';
    public const SELECTION_AUTOMATIC = 'automatic';
    public const SELECTION_MIXED = 'mixed';

    public const LANGUAGE_ENGLISH = 'english';
    public const LANGUAGE_URDU = 'urdu';
    public const LANGUAGE_BILINGUAL = 'bilingual';

    protected $table = 'teacher_class_tests';

    protected $fillable = [
        'title',
        'description',
        'instructions',
        'selection_method',
        'selection_criteria_json',
        'marks_per_question',
        'negative_marks_per_wrong',
        'language_mode',
    ];

    protected $casts = [
        'teacher_class_id' => 'integer',
        'teacher_profile_id' => 'integer',
        'curriculum_board_id' => 'integer',
        'class_id' => 'integer',
        'subject_id' => 'integer',
        'selection_criteria_json' => 'array',
        'total_questions' => 'integer',
        'marks_per_question' => 'decimal:2',
        'total_marks' => 'decimal:2',
        'negative_marks_per_wrong' => 'decimal:2',
        'test_version' => 'integer',
        'scheduled_start_at' => 'datetime',
        'scheduled_end_at' => 'datetime',
        'duration_minutes' => 'integer',
        'max_attempts' => 'integer',
        'allow_answer_review' => 'boolean',
        'results_released_at' => 'datetime',
        'published_at' => 'datetime',
        'closed_at' => 'datetime',
        'archived_at' => 'datetime',
    ];

    public function scopeForClass(Builder $query, TeacherClass $class): Builder
    {
        return $query
            ->where('teacher_class_id', $class->id)
            ->where('teacher_profile_id', $class->teacher_profile_id);
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function teacherClass(): BelongsTo
    {
        return $this->belongsTo(TeacherClass::class, 'teacher_class_id');
    }

    public function teacherProfile(): BelongsTo
    {
        return $this->belongsTo(TeacherProfile::class, 'teacher_profile_id');
    }

    public function questions(): HasMany
    {
        return $this->hasMany(TeacherClassTestQuestion::class, 'teacher_class_test_id')
            ->orderBy('question_order')
            ->orderBy('id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(TeacherClassTestAssignment::class, 'teacher_class_test_id')
            ->orderByDesc('assigned_at')
            ->orderByDesc('id');
    }
}
