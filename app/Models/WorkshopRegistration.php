<?php
// app/Models/WorkshopRegistration.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WorkshopRegistration extends Model
{
    use HasFactory;

    protected $fillable = [
        'workshop_id',
        'user_id',
        'status',
        'registered_at',
        'attendance_marked_at',
        'attendance_marked_by',
    ];

    protected $casts = [
        'registered_at' => 'datetime',
        'attendance_marked_at' => 'datetime',
    ];

    public function workshop()
    {
        return $this->belongsTo(Workshop::class, 'workshop_id');
    }

    public function student()
    {
        return $this->belongsTo(WebUser::class, 'user_id');
    }

    public function attendanceMarker()
    {
        return $this->belongsTo(User::class, 'attendance_marked_by');
    }
}