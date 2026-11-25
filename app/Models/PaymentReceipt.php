<?php

namespace App\Models;

use App\Http\Services\AwsUploadService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentReceipt extends Model
{
    use HasFactory;

    /**
     * Protect sensitive storage paths from unauthenticated/direct model serialization.
     */
    protected $hidden = [
        'pdf_path',
    ];

    protected $fillable = [
        'receipt_number',
        'subscription_payment_request_id',
        'financial_transaction_id',
        'user_id',
        'amount',
        'currency',
        'payment_method',
        'payment_channel',
        'transaction_reference',
        'issued_at',
        'pdf_path',
        'email_sent_to',
        'email_sent_at',
        'email_status',
        'status',
        'notes',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'issued_at' => 'datetime',
        'email_sent_at' => 'datetime',
    ];

    public function paymentRequest(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPaymentRequest::class, 'subscription_payment_request_id');
    }

    public function financialTransaction(): BelongsTo
    {
        return $this->belongsTo(FinancialTransaction::class, 'financial_transaction_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(WebUser::class, 'user_id');
    }
}
