<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProgramSubject extends Model
{
    use HasFactory;
    protected $fillable = [
        'offered_program_id',
        'subject_id',
        'display_order',
        'is_demo_available',
        'is_free',
        'is_active',
    ];
    public function offeredProgram(){
        return $this->belongsTo(OfferedProgram::class, 'offered_program_id');
    }
    public function subject(){
        return $this->belongsTo(Subject::class, 'subject_id');
    }

}
