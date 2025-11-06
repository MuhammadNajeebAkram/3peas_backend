<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ModelPaper extends Model
{
    use HasFactory;
    protected $fillable = [
        'title',
        'total_marks',
        'time_allowed',
        'class_id',
        'subject_id',
        'instructions',
        'urdu_lang',
        'total_questions'        

    ];

    

    public function paperParts(){
        return $this->hasMany(PaperParts::class);
    }

    public function paperClass(){
        return $this->belongsTo(UserClass::class, 'class_id');
    }

    public function paperSubject(){
        return $this->belongsTo(Subject::class, 'subject_id');
    }

    
}
