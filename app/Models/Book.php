<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Book extends Model
{
    use HasFactory;
    protected $table = 'book_tbl';
    protected $fillable = [
        'book_name',
        'class_id',
        'subject_id',
        'curriculum_board_id',
        'activate',
    ];

    public function subject(){
        return $this->belongsTo(Subject::class, 'subject_id');
    }

    public function userClass(){
        return $this->belongsTo(UserClass::class, 'class_id');
    }

    public function curriculmBoard(){
        return $this->belongsTo(CurriculumBoard::class, 'curriculum_board_id');
    }

    public function units(){
        return $this->hasMany(BookUnit::class, 'book_id');
    }
}
