<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class QuestionPresentationType extends Model
{
    use HasFactory;

    protected $table = 'question_presentation_type_tbl';

    protected $fillable = [
        'type_name',
        'code',
        'description',
        'activate',
        'sort_order',
    ];
}
