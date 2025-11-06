<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaperPartSection extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'no_of_questions',
        'paper_part_id',
        'show_name',
        'sequence',
        'urdu_lang',


    ];

    public function paperPart(){
        return $this->belongsTo(PaperParts::class);
    }
    public function questions(){
        return $this->hasMany(PaperPartSectionQuestion::class, 'section_id');
    }
}
