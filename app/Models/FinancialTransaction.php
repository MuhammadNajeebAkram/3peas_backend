<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinancialTransaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'transaction_number',
        'transaction_type',
        'reference_type',
        'reference_id',
        'transaction_date',
        'amount',
        'currency',
        'status',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'transaction_date' => 'datetime',
        'amount' => 'decimal:2',
    ];

    public function entries(): HasMany
    {
        return $this->hasMany(FinancialLedgerEntry::class);
    }

    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isBalanced(): bool
    {
        $totalDebit = (float) $this->entries()->where('entry_type', 'debit')->sum('amount');
        $totalCredit = (float) $this->entries()->where('entry_type', 'credit')->sum('amount');

        return abs($totalDebit - $totalCredit) < 0.001;
    }
}
