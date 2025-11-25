<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BookUnitTopic extends Model
{
    use HasFactory;
    protected $table = 'book_unit_topic_tbl';

    protected $fillable = [
        'unit_id',
        'topic_name',
        'topic_name_um',
        'topic_no',
        'activate',
        'is_alp'
    ];

    public function bookUnit(){
        return $this->belongsTo(BookUnit::class);
    }

    public function questions(){
        return $this->hasMany(ExamQuestion::class, 'topic_id');
    }
}
