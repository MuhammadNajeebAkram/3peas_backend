<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TeacherSubscriptionPlan extends Model
{
    public const FEATURE_KEYS = [
        'classes.manage', 'students.enroll', 'question_bank.access', 'tests.create',
        'tests.assign', 'reports.basic', 'announcements.manage', 'messaging.use',
    ];

    protected $guarded = ['id', 'active_default_guard'];

    protected $casts = [
        'price' => 'decimal:2',
        'duration_days' => 'integer',
        'max_active_classes' => 'integer',
        'is_free' => 'boolean',
        'is_active' => 'boolean',
        'is_default' => 'boolean',
    ];

    public function features(): HasMany
    {
        return $this->hasMany(TeacherSubscriptionFeature::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(TeacherSubscription::class);
    }

    public function history(): HasMany
    {
        return $this->hasMany(TeacherSubscriptionPlanHistory::class, 'teacher_subscription_plan_id');
    }
}
