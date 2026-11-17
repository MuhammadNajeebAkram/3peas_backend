<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeacherClassGradebookAssessmentExclusion extends Model
{
    public const TYPE_MCQ_TEST = 'mcq_test';
    public const TYPE_ASSIGNMENT = 'assignment';

    protected $table = 'teacher_class_gradebook_assessment_exclusions';

    protected $fillable = [
        'teacher_class_id',
        'assessment_type',
        'source_id',
        'is_excluded',
        'updated_by_web_user_id',
    ];

    protected $casts = [
        'source_id' => 'integer',
        'is_excluded' => 'boolean',
    ];

    public function teacherClass(): BelongsTo
    {
        return $this->belongsTo(TeacherClass::class, 'teacher_class_id');
    }
}
