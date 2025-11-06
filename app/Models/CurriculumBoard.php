<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CurriculumBoard extends Model
{
    use HasFactory;
    protected $table = 'curriculum_board_tbl';

    public function modelPapers(){
        return $this->hasMany(ModelPaper::class, 'curriculum_board_id');
    }

    public function books(){
        return $this->hasMany(Book::class, 'curriculum_board_id');
    }
}
