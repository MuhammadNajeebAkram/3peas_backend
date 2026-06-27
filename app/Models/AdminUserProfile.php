<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AdminUserProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'phone',
        'alternate_phone',
        'avatar_url',
        'designation',
        'department',
        'bio',
        'address',
        'city',
        'province',
        'country',
        'timezone',
        'locale',
        'notification_preferences',
        'emergency_contact_name',
        'emergency_contact_phone',
        'bank_name',
        'bank_account_no',
        'bank_iban_no',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'notification_preferences' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
