<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PastPaper extends Model
{
    use HasFactory;
    protected $table = 'past_paper_tbl';

    protected $fillable = [
        'paper_name',
        'paper_slug',
        'paper_path',
        'class_id',
        'subject_id',
        'board_id',
        'year',
        'session_id',
        'group',
        'activate'

    ];

    
}
