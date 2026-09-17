<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TeacherSettlementAllocation extends Model
{
    protected $guarded = ['id'];
    protected $casts = ['amount' => 'decimal:2'];

    public function paymentRequest()
    {
        return $this->belongsTo(SubscriptionPaymentRequest::class, 'payment_request_id');
    }
}
