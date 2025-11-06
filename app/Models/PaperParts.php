<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaperParts extends Model
{
    use HasFactory;
    protected $fillable = [
        'name',
        'name_um',
        'max_marks',
        'max_marks_um',
        'time_allowed',
        'time_allowed_um',
        'model_paper_id',
        'sequence'


    ];

    

    public function modelPaper(){
        return $this->belongsTo(ModelPaper::class);
    }

    public function partSections(){
        return $this->hasMany(PaperPartSection::class, 'paper_part_id');
    }
}
