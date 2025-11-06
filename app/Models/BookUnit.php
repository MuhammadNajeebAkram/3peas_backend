<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BookUnit extends Model
{
    use HasFactory;
     protected $table = 'book_unit_tbl';


     public function book(){
        return $this->belongsTo(Book::class);
     }
}
