<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class QuestionScenarioGroup extends Model
{
    use HasFactory;

    protected $table = 'question_scenario_groups_tbl';

    protected $fillable = [
        'title',
        'scenario_text',
        'scenario_text_um',
        'scenario_image',
        'question_presentation_type_id',
        'topic_id',
        'unit_id',
        'book_id',
        'activate',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'activate' => 'boolean',
    ];

    public function presentationType()
    {
        return $this->belongsTo(QuestionPresentationType::class, 'question_presentation_type_id');
    }

    public function questions()
    {
        return $this->hasMany(ExamQuestion::class, 'scenario_group_id')
            ->orderBy('scenario_question_order')
            ->orderBy('id');
    }
}
