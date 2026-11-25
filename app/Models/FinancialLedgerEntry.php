<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinancialLedgerEntry extends Model
{
    use HasFactory;

    protected $fillable = [
        'financial_transaction_id',
        'financial_ledger_account_id',
        'entry_type',
        'amount',
        'description',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(FinancialTransaction::class, 'financial_transaction_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(FinancialLedgerAccount::class, 'financial_ledger_account_id');
    }
}
