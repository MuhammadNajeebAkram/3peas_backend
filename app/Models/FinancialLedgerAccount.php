<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FinancialLedgerAccount extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'type',
        'currency',
        'is_active',
        'payment_account_id',
        'description',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function paymentAccount(): BelongsTo
    {
        return $this->belongsTo(PaymentAccount::class);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(FinancialLedgerEntry::class);
    }

    public function getBalanceAttribute(): float
    {
        // Normal balances:
        // Asset & Expense: Debit increases (+), Credit decreases (-)
        // Liability, Equity & Revenue: Credit increases (+), Debit decreases (-)
        $debits = (float) $this->entries()->where('entry_type', 'debit')->sum('amount');
        $credits = (float) $this->entries()->where('entry_type', 'credit')->sum('amount');

        if (in_array($this->type, ['asset', 'expense'])) {
            return $debits - $credits;
        }

        return $credits - $debits;
    }
}
