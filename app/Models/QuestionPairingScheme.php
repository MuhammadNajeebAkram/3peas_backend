<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class QuestionPairingScheme extends Model
{
    use HasFactory;
    protected $fillable = [
        'sub_section_id',
        'unit_id',
        'no_of_questions',
        'activate'
    ];

    public function subSection(){
        return $this->belongsTo('paper_question_section_sub_sections');
    }
    public function units(){
        return $this->belongsTo('unit_tbl');
    }
}
