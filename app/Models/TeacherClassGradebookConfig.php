<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TeacherClassGradebookConfig extends Model
{
    public const RETAKE_HIGHEST = 'highest';
    public const RETAKE_LATEST = 'latest';
    public const RETAKE_AVERAGE = 'average';

    public const RETAKE_POLICIES = [
        self::RETAKE_HIGHEST,
        self::RETAKE_LATEST,
        self::RETAKE_AVERAGE,
    ];

    protected $table = 'teacher_class_gradebook_configs';

    protected $fillable = [
        'teacher_class_id',
        'retake_score_policy',
        'weighting_enabled',
        'study_session_id',
        'effective_from',
        'effective_to',
        'updated_by_web_user_id',
    ];

    protected $casts = [
        'weighting_enabled' => 'boolean',
        'study_session_id' => 'integer',
        'effective_from' => 'date',
        'effective_to' => 'date',
    ];

    public function teacherClass(): BelongsTo
    {
        return $this->belongsTo(TeacherClass::class, 'teacher_class_id');
    }

    public function studySession(): BelongsTo
    {
        return $this->belongsTo(StudySession::class, 'study_session_id');
    }

    public function categoryRules(): HasMany
    {
        return $this->hasMany(TeacherClassGradebookCategoryRule::class, 'gradebook_config_id')->orderBy('id');
    }
}
