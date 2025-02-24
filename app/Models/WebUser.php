<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable; // Correct import for Authenticatable
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Tymon\JWTAuth\Contracts\JWTSubject;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Notifications\CustomVerifyEmail;
use App\Models\WebUserProfile;
use App\Models\UserPaymentSlip;

class WebUser extends Authenticatable implements JWTSubject, MustVerifyEmail
{
    use HasFactory, Notifiable;

    // Add these methods for JWTSubject
    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims()
    {
        return [];
    }

    public function sendEmailVerificationNotification()
    {
        $this->notify(new CustomVerifyEmail());
    }

    protected $table = 'web_users'; // Ensure it uses the correct table

    // Add the 'fillable' property for mass assignment
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'study_session_id',
       // 'email_verified_at',
    ];

    // Hide sensitive data
    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime', // Cast verification date
    ];

    public function profile()
    {
        return $this->hasOne(WebUserProfile::class, 'user_id', 'id');
    }

    public function paymentSlip()
    {
        return $this->hasOne(UserPaymentSlip::class, 'user_id', 'id');
    }
}
