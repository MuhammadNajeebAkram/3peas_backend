<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ExamQuestionAnswer extends Model
{
    use HasFactory;
    protected $table = 'exam_answer_tbl';

    public function question(){
        return $this->belongsTo(ExamQuestion::class);
    }
}
