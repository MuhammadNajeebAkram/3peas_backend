<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ExamQuestion extends Model
{
    use HasFactory;
    protected $table = 'exam_question_tbl';

    public function topic(){
        return $this->belongsTo(BookUnitTopic::class);
    }
    public function questionType(){
        return $this->belongsTo(QuestionType::class, 'question_type');
    }
    public function cognitiveDomain(){

    }
    public function topicContent(){

    }

    public function answers(){
        return $this->hasMany(ExamQuestionAnswer::class, 'question_id');

    }
    public function answerOptions(){
        return $this->hasMany(ExamQuestionAnswerOption::class, 'question_id');

    }

    public function scenarioGroup(){
        return $this->belongsTo(QuestionScenarioGroup::class, 'scenario_group_id');
    }

    public function aiQuestionGeneration(){
        return $this->belongsTo(AiQuestionGeneration::class, 'ai_question_generation_id');
    }

    public function aiProvider(){
        return $this->belongsTo(AiProvider::class, 'ai_provider_id');
    }

    public function aiModel(){
        return $this->belongsTo(AiModel::class, 'ai_model_id');
    }

    public function sloTopic(){
        return $this->belongsTo(SloTopic::class, 'slo_topic_id');
    }

    public function reports()
    {
        return $this->hasMany(QuestionReport::class, 'question_id');
    }
}
