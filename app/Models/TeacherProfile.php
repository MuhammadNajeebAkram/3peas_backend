<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class TeacherProfile extends Model
{
    // Approval fields and codes must be assigned explicitly by trusted backend code.
    protected $fillable = ['web_user_id', 'institute_id', 'city_id'];

    protected $attributes = ['status' => 'pending'];

    protected $hidden = ['admin_note'];

    protected $casts = [
        'approved_at' => 'datetime',
        'status_changed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (TeacherProfile $profile) {
            if (blank($profile->teacher_code)) {
                do {
                    $code = 'TCH-'.Str::upper(Str::random(12));
                } while (static::where('teacher_code', $code)->exists());

                $profile->teacher_code = $code;
            }
        });
    }

    public function webUser(): BelongsTo
    {
        return $this->belongsTo(WebUser::class);
    }

    public function institute(): BelongsTo
    {
        return $this->belongsTo(Institute::class);
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function statusChangedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'status_changed_by');
    }
}
