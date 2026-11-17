<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeacherSubscriptionHistory extends Model
{
    protected $table = 'teacher_subscription_history';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = [
        'previous_expiry' => 'datetime',
        'new_expiry' => 'datetime',
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(TeacherSubscription::class);
    }

    public function previousPlan(): BelongsTo
    {
        return $this->belongsTo(TeacherSubscriptionPlan::class, 'previous_plan_id');
    }

    public function newPlan(): BelongsTo
    {
        return $this->belongsTo(TeacherSubscriptionPlan::class, 'new_plan_id');
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }
}
