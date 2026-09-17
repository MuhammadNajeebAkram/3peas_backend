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
        'price',
        'discount_amount',
        'discount_percentage',
        'final_amount',
        'transaction_id',
        'payer_name',
        'payer_phone',
        'proof_file_path',
        'status',
        'approved_at',
        'approved_by',
        'rejected_by',
        'rejection_reason',
        'admin_remarks',
        'payment_method',
        'teacher_profile_id',
        'collection_code_snapshot',
        'confirmed_by_web_user_id',
        'confirmed_at',
        'rejected_by_web_user_id',
        'receipt_number',

    ];

    protected $casts = [
        'confirmed_at' => 'datetime',
        'price' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'discount_percentage' => 'decimal:2',
        'final_amount' => 'decimal:2',
        'approved_at' => 'date',
    ];
    public function userApproved(){
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function teacherProfile(){
        return $this->belongsTo(TeacherProfile::class);
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
