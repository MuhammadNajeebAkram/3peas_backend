<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StudyPlan extends Model
{
    use HasFactory;
    protected $table = 'study_plan_tbl';
    protected $fillable = [
        'name',
        'price',
        'plan_for',
        'is_full_course',
        'activate',
        'class_id',
        'curriculum_board_id',
        'is_trial',
        'session_id',

    ];

     public function planClass(){
        return $this->belongsTo(UserClass::class, 'class_id');

    }
    public function planBoard(){
        return $this->belongsTo(CurriculumBoard::class, 'curriculum_board_id');

    }
}
