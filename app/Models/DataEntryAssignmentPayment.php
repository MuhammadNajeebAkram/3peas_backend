<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DataEntryAssignmentPayment extends Model
{
    use HasFactory;

    protected $fillable = [
        'assignment_id',
        'paid_by',
        'approved_units',
        'payable_amount',
        'paid_amount',
        'payment_status',
        'payment_method',
        'transaction_reference',
        'remarks',
        'paid_at',
    ];

    protected $casts = [
        'approved_units' => 'decimal:2',
        'payable_amount' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'paid_at' => 'datetime',
    ];

    public function assignment()
    {
        return $this->belongsTo(DataEntryAssignment::class, 'assignment_id');
    }

    public function paidBy()
    {
        return $this->belongsTo(User::class, 'paid_by');
    }
}
