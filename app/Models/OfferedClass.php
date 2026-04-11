<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OfferedClass extends Model
{
    use HasFactory;

    protected $fillable = [
        'class_id',
        'curriculum_board_id',
        'price',
        'discount_price',
        'discount_percent',
        'is_free',
        'session_start',
        'session_end',
        'is_active',
        'display_order',
    ];
    

    public function userClass(){
        return $this->belongsTo(UserClass::class, 'class_id');
    }
    public function curriculumBoard(){
        return $this->belongsTo(CurriculumBoard::class, 'curriculum_board_id');
    }
    public function offeredPrograms(){
        return $this->hasMany(OfferedProgram::class, 'offered_class_id');
    }
}
