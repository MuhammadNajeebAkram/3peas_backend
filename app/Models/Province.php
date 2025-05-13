<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Province extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'activate',
    ];

    protected static function boot()
    {
        parent::boot();
    
        static::creating(function ($model) {
            
               
                $model->created_at = Carbon::now();
                $model->updated_at = Carbon::now();
            
        });
    
        static::updating(function ($model) {
           
               
                $model->updated_at = Carbon::now();
            
        });
    }

    public function divisions() {
        return $this->hasMany(Division::class);
    }
    
}
