<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeacherSubscriptionFeature extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['is_enabled' => 'boolean'];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(TeacherSubscriptionPlan::class, 'teacher_subscription_plan_id');
    }
}
