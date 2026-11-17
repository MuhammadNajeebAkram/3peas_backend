<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PastPaper extends Model
{
    use HasFactory;
    protected $table = 'past_paper_tbl';

    protected $fillable = [
        'paper_name',
        'paper_slug',
        'paper_path',
        'class_id',
        'subject_id',
        'board_id',
        'curriculum_board_id',
        'year',
        'session_id',
        'group',
        'activate'

    ];

    protected $casts = [
        'curriculum_board_id' => 'integer',
    ];

    public function curriculumBoard()
    {
        return $this->belongsTo(CurriculumBoard::class, 'curriculum_board_id');
    }

    public function board()
    {
        return $this->belongsTo(ExamBoard::class, 'board_id');
    }

    public function subject()
    {
        return $this->belongsTo(Subject::class, 'subject_id');
    }

    public function academicClass()
    {
        return $this->belongsTo(UserClass::class, 'class_id');
    }

    
}
