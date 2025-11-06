<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserClass extends Model
{
    use HasFactory;

    protected $table = 'class_tbl';

     public function books(){
        return $this->hasMany(Book::class, 'class_id');
    }

    public function modelPapers(){
        return $this->hasMany(ModelPaper::class);
    }
}
