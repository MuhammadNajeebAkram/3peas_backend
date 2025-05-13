<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Institute extends Model
{
    use HasFactory;

    protected $table = 'institute_tbl';

    // Define the primary key (optional if it's 'id')
    protected $primaryKey = 'id';

    // Disable timestamps if the table doesn't have created_at and updated_at
    public $timestamps = true;

    protected $fillable = [
        'name',
        'address',
        'city_id',
        'phone',
        'sector',
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

    public function City(){
        $this->belongsTo(City::class, 'city_id');
    }
   
}
