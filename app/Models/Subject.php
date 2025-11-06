<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Subject extends Model
{
    use HasFactory;

    protected $table = 'subject_tbl';

    
    
    public function books(){
        return $this->hasMany(Book::class, 'subject_id');
    }


    public function modelPapers(){
        return $this->hasMany(ModelPaper::class, 'subject_id');
    }
}
