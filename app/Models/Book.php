<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Book extends Model
{
    use HasFactory;
     protected $table = 'book_tbl';

     public function subject(){
        return $this->belongsTo(Subject::class);
     }
     public function userClass(){
        return $this->belongsTo(UserClass::class);
     }
      public function curriculmBoard(){
        return $this->belongsTo(CurriculumBoard::class);
     }

     public function units(){
        return $this->hasMany(BookUnit::class, 'book_id');
     }
}
