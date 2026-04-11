<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SubscriptionPaymentRequest extends Model
{
    use HasFactory;
    protected $fillable = [
        'user_id',  
       'offered_program_id',
       'subscription_id',
       'payment_account_id',
       'amount',
       'transaction_id',
       'payer_name',
       'payer_phone',
       'proof_file_path',
       'status',
       'approved_at',
       'approved_by',
       'rejected_by',
       'rejection_reason'

    ];
    public function userApproved(){
        return $this->belongsTo(User::class, 'approved_by');
    }
    public function userRejected(){
        return $this->belongsTo(User::class, 'rejected_by');
    }
    public function userSubscription(){
        return $this->belongsTo(UserSubscription::class, 'subscription_id');    
    }
    public function offeredProgram(){
        return $this->belongsTo(OfferedProgram::class, 'offered_program_id');
    }
    public function paymentAccount(){
        return $this->belongsTo(PaymentAccount::class, 'payment_account_id');
    }

    public function user(){
        return $this->belongsTo(WebUser::class, 'user_id');
    }
    

}
