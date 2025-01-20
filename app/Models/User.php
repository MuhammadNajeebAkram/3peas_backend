<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable; // Correct import for Authenticatable
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Tymon\JWTAuth\Contracts\JWTSubject;

class User extends Authenticatable implements JWTSubject
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

    // Add the 'fillable' property for mass assignment
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    // Hide sensitive data
    protected $hidden = [
        'password',
        'remember_token',
    ];
}
