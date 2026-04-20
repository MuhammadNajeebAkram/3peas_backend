<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserSubscription extends Model
{
    use HasFactory;
    protected $fillable = [
        'user_id',
        'offered_program_id',
        'status',
        'access_type',
        'price_paid',
        'started_at',
        'expires_at',
        'approved_at',
        'approved_by'

    ];

    protected $casts = [
        'price_paid' => 'decimal:2',
        'started_at' => 'date',
        'expires_at' => 'date',
        'approved_at' => 'date',
    ];
    public function webUser(){
        return $this->belongsTo(WebUser::class, 'user_id');
    }
    public function offeredProgram(){
        return $this->belongsTo(OfferedProgram::class, 'offered_program_id');
    }
    public function userApprovedBy(){
        return $this->belongsTo(User::class, 'approved_by');
    }
    
    public function subscriptionPaymentRequests(){
        return $this->hasMany(SubscriptionPaymentRequest::class, 'subscription_id');
    }
}
