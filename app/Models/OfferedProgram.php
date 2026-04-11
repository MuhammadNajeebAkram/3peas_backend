<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OfferedProgram extends Model
{
    use HasFactory;
    protected $fillable = [
        'offered_class_id',
        'title',
        'slug',
        'description',
        'display_order',
        'is_active',
    ];
    public function offeredClass(){
        return $this->belongsTo(OfferedClass::class, 'offered_class_id');
    }
    public function programSubjects(){
        return $this->hasMany(ProgramSubject::class, 'offered_program_id'); 
    }
    
}
