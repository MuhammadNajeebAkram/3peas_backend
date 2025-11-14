<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaperQuestionSectionSubSection extends Model
{
    use HasFactory;
    protected $fillable = [
        'section_id',
        'sub_section_name',
        'total_questions',
        'question_type_id',
        'is_random_units',
        'no_of_random_units',
        'activate',
        'show_name',
        'show_question_statement'
    ];

    public function section(){
        return $this->belongsTo(PaperQuestionSection::class);
    }

    public function pairingSchemes(){
        return $this->hasMany(QuestionPairingScheme::class, 'sub_section_id');
    }

    
}
