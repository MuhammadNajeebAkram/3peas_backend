<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaperPartSectionQuestion extends Model
{
    use HasFactory;
    protected $fillable = [
        'question_statement',
        'question_no',
        'marks',
        'section_id',
        'urdu_lang',
        'sequence',
        'activate',
        'is_get_statement',        

    ] ;

    public function partSection(){
        return $this->belongsTo(PaperPartSection::class);
    }
    public function sections(){
        return $this->hasMany(PaperQuestionSection::class, 'question_id');
    }
}
