<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DataEntryAssignment extends Model
{
    use HasFactory;

    public const PAYMENT_PER_QUESTION = 'per_question';
    public const PAYMENT_PER_PAGE = 'per_page';
    public const PAYMENT_FIXED = 'fixed';

    protected $fillable = [
        'assigned_to',
        'assigned_by',
        'module_type',
        'title',
        'instructions',
        'target_quantity',
        'payment_type',
        'rate_per_unit',
        'fixed_amount',
        'status',
        'due_date',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'target_quantity' => 'integer',
        'rate_per_unit' => 'decimal:2',
        'fixed_amount' => 'decimal:2',
        'due_date' => 'date',
    ];

    public function assignedTo()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function assignedBy()
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function items()
    {
        return $this->hasMany(DataEntryAssignmentItem::class, 'assignment_id');
    }

    public function payments()
    {
        return $this->hasMany(DataEntryAssignmentPayment::class, 'assignment_id');
    }
}
