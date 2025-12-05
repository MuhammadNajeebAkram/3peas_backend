<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserStudyPlan extends Model
{
    use HasFactory;
    protected $table = 'user_study_plan_tbl';
    protected $fillable = [
        'user_id',
        'study_plan_id',
        'qty',
        'price',
    ];

    public function user(){
        return $this->belongsTo(WebUser::class, 'user_id');
    }
    public function plan(){
        return $this->belongsTo(StudyPlan::class, 'study_plan_id');
    }
}
