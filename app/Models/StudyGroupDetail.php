<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StudyGroupDetail extends Model
{
    use HasFactory;
    protected $table = 'study_group_detail_tbl';

    protected $fillable = [
        'study_group_id',
        'subject_id',
    ];

    public function studyGroup(){
        return $this->belongsTo(StudyGroup::class, 'study_group_id');
    }
    public function subject(){
        return $this->belongsTo(Subject::class);
    }
}
