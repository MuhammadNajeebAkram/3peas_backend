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
        'role_id',
        'is_active',
    ];

    // Hide sensitive data
    protected $hidden = [
        'password',
        'remember_token',
    ];

    public function role()
    {
        return $this->belongsTo(Role::class, 'role_id');
    }

    public function createdWorkshops()
{
    return $this->hasMany(Workshop::class, 'created_by');
}

public function updatedWorkshops()
{
    return $this->hasMany(Workshop::class, 'updated_by');
}

public function markedWorkshopAttendances()
{
    return $this->hasMany(WorkshopRegistration::class, 'attendance_marked_by');
}
}
