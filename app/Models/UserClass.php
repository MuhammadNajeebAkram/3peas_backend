<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserClass extends Model
{
    protected $table = 'class_tbl';
    use HasFactory;

    protected $fillable = [
        'class_name',
        'class_name_um',
        'slug',
    ];

    

     public function books(){
        return $this->hasMany(Book::class, 'class_id');
    }

    public function modelPapers(){
        return $this->hasMany(ModelPaper::class);
    }
    public function offeredClasses(){
        return $this->hasMany(OfferedClass::class, 'class_id');
    }
}
