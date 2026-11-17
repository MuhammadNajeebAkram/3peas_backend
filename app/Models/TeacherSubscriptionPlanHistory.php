<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeacherSubscriptionPlanHistory extends Model
{
    protected $table = 'teacher_subscription_plan_history';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = [
        'previous_values' => 'array',
        'new_values' => 'array',
        'created_at' => 'datetime',
    ];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(TeacherSubscriptionPlan::class, 'teacher_subscription_plan_id');
    }

    public function adminUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_user_id');
    }
}
