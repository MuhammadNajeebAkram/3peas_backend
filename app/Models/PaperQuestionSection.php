<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaperQuestionSection extends Model
{
    use HasFactory;
    protected $fillable = [
         'question_id',
        'section_name',
        'no_of_sub_sections',
        'is_random_question_type',
        'activate',

    ];

    public function question(){
        return $this->belongsTo(PaperPartSectionQuestion::class);
    }

    public function subSections(){
        return $this->hasMany(PaperQuestionSectionSubSection::class, 'section_id');
    }
}
