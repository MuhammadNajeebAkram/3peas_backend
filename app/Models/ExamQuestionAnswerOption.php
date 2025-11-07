<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ExamQuestionAnswerOption extends Model
{
    use HasFactory;
    protected $table = 'exam_question_options_tbl';

    public function question(){
        return $this->belongsTo(ExamQuestion::class);
    }
}
