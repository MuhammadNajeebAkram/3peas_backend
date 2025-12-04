<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StudySession extends Model
{
    use HasFactory;
    protected $table = 'study_session_tbl';

    protected $fillable = [
        'name',
        'start_date',
        'end_date',
        'class_id',
        'curriculum_board_id',
        'activate',
    ];

    protected $casts = [
    'start_date' => 'datetime',
    'end_date' => 'datetime', // Assuming you have an end_date field
];

    public function sessionClass(){
        return $this->belongsTo(UserClass::class, 'class_id');

    }
    public function sessionBoard(){
        return $this->belongsTo(CurriculumBoard::class, 'curriculum_board_id');

    }
}
