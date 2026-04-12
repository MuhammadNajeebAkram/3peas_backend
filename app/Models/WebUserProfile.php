<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WebUserProfile extends Model
{
    use HasFactory;

    // Define table name explicitly (optional if table name follows Laravel's convention)
    protected $table = 'user_profile_tbl';

    // Define the primary key (optional if it's 'id')
    protected $primaryKey = 'id';

    // Disable timestamps if the table doesn't have created_at and updated_at
    public $timestamps = true;

    // Define fillable fields
    protected $fillable = [
        'user_id', 
        'address', 
        'city_id',        
        'profile_completed',        
        'institute_id', 
        'gender_id',
        'dob',
        'designation',        
        'heard_about_id', 
        'preferred_language', 
        'referral_code',     
       
    ];

    public function user(){
        return $this->belongsTo(WebUser::class, 'user_id');
            }
    
   
    public function userInstitute(){
        return $this->belongsTo(Institute::class, 'institute_id');
    }
     public function userCity(){
        return $this->belongsTo(City::class, 'city_id');
    }


    
}
