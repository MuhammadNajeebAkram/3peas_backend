<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TeacherSubscription extends Model
{
    protected $guarded = ['id', 'active_teacher_profile_id'];

    protected $casts = ['starts_at' => 'datetime', 'expires_at' => 'datetime'];

    public function teacherProfile(): BelongsTo
    {
        return $this->belongsTo(TeacherProfile::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(TeacherSubscriptionPlan::class, 'teacher_subscription_plan_id');
    }

    public function history(): HasMany
    {
        return $this->hasMany(TeacherSubscriptionHistory::class);
    }
}
