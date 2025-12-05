<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StudyGroup extends Model
{
    use HasFactory;
    protected $table = 'study_group_tbl';

    protected $fillable = [
        'name',
        'class_id',
        'curriculum_board_id',
        'activate',
    ];

    public function studyClass(){
        return $this->belongsTo(UserClass::class, 'class_id');
    }

    public function studyBoard(){
        return $this->belongsTo(CurriculumBoard::class, 'curriculum_board_id');
    }

    public function subjects(){
        return $this->hasMany(StudyGroupDetail::class, 'study_group_id');
    }
}
