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
        return $this->belongsTo(QuestionType::class);
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



}
