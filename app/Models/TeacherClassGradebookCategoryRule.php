<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeacherClassGradebookCategoryRule extends Model
{
    public const CAT_MCQ_TEST = 'mcq_test';
    public const CAT_ASSIGNMENT = 'assignment';

    public const CATEGORIES = [
        self::CAT_MCQ_TEST,
        self::CAT_ASSIGNMENT,
    ];

    protected $table = 'teacher_class_gradebook_category_rules';

    protected $fillable = [
        'gradebook_config_id',
        'teacher_class_id',
        'category_key',
        'label',
        'weight_percent',
        'is_included',
        'exclude_ungraded',
    ];

    protected $casts = [
        'weight_percent' => 'float',
        'is_included' => 'boolean',
        'exclude_ungraded' => 'boolean',
    ];

    public function config(): BelongsTo
    {
        return $this->belongsTo(TeacherClassGradebookConfig::class, 'gradebook_config_id');
    }
}
