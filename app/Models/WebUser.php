<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use App\Notifications\CustomVerifyEmail;
use Tymon\JWTAuth\Contracts\JWTSubject;

class WebUser extends Authenticatable implements JWTSubject, MustVerifyEmail
{
    use HasFactory, Notifiable, SoftDeletes;

    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims()
    {
        return [];
    }

    public function sendEmailVerificationNotification()
    {
        $this->notify(new CustomVerifyEmail());
    }

    protected $table = 'web_users';

    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'role',
        'study_session_id',
        'email_verified_at',
        'last_login_at',
        'last_token',
        'google_id',
        'avatar',
        'login_provider',
        'status',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'last_token',
    ];

    protected $casts = [
        'study_session_id' => 'integer',
        'email_verified_at' => 'datetime',
        'last_login_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function profile(): HasOne
    {
        return $this->hasOne(WebUserProfile::class, 'user_id', 'id');
    }

    public function paymentSlip(): HasOne
    {
        return $this->hasOne(UserPaymentSlip::class, 'user_id', 'id');
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(UserSubscription::class, 'user_id', 'id');
    }

    public function studySession(): BelongsTo
    {
        return $this->belongsTo(StudySession::class, 'study_session_id');
    }

    public function studyPlans(): HasMany
    {
        return $this->hasMany(UserStudyPlan::class, 'user_id', 'id');
    }

    public function subscriptionPaymentRequests(): HasMany
    {
        return $this->hasMany(SubscriptionPaymentRequest::class, 'user_id', 'id');
    }

    public function practiceSessions(): HasMany
    {
        return $this->hasMany(PracticeSession::class, 'user_id', 'id');
    }

    public function testAttempts(): HasMany
    {
        return $this->hasMany(TestAttempt::class, 'user_id', 'id');
    }

    public function studentActivities(): HasMany
    {
        return $this->hasMany(StudentActivity::class, 'user_id', 'id');
    }

    public function studentQuestionProgressSummaries(): HasMany
    {
        return $this->hasMany(StudentQuestionProgressSummary::class, 'user_id', 'id');
    }

    public function studentSubjectProgressSummaries(): HasMany
    {
        return $this->hasMany(StudentSubjectProgressSummary::class, 'user_id', 'id');
    }

    public function studentUnitProgressSummaries(): HasMany
    {
        return $this->hasMany(StudentUnitProgressSummary::class, 'user_id', 'id');
    }
}
