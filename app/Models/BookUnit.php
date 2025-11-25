<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BookUnit extends Model
{
    use HasFactory;
     protected $table = 'book_unit_tbl';

     protected $fillable = [
        'book_id',
        'unit_name',
        'unit_no',
        'activate',
        'is_alp'
     ];


     public function book(){
        return $this->belongsTo(Book::class);
     }

     public function topics(){
      return $this->hasMany(BookUnitTopic::class, 'unit_id');
     }
}
