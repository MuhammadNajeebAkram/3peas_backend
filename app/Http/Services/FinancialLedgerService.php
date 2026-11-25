<?php

namespace App\Http\Services;

use App\Models\FinancialLedgerAccount;
use App\Models\FinancialLedgerEntry;
use App\Models\FinancialTransaction;
use App\Models\PaymentAccount;
use App\Models\SubscriptionPaymentRequest;
use App\Models\TeacherSettlement;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class FinancialLedgerService
{
    /**
     * Ensure standard LMS chart of accounts exist.
     */
    public function ensureDefaultAccounts(): void
    {
        $defaults = [
            [
                'code' => '1010-CASH',
                'name' => 'Cash in Hand',
                'type' => 'asset',
                'description' => 'General cash account for direct collections',
            ],
            [
                'code' => '1020-BANK-MEEZAN',
                'name' => 'Meezan Bank Ltd',
                'type' => 'asset',
                'description' => 'Meezan Bank collection account for bank deposit/transfer',
            ],
            [
                'code' => '1030-WALLET-JAZZCASH',
                'name' => 'JazzCash Mobile Wallet',
                'type' => 'asset',
                'description' => 'JazzCash mobile wallet collection account',
            ],
            [
                'code' => '1040-TEACHER-CLEARING',
                'name' => 'Teacher Collections Clearing Account',
                'type' => 'asset',
                'description' => 'Clearing / receivable account holding subscription funds collected by authorized teachers pending settlement',
            ],
            [
                'code' => '2010-UNEARNED-REVENUE',
                'name' => 'Unearned Subscription Revenue',
                'type' => 'liability',
                'description' => 'Deferred revenue for prepaid student subscriptions',
            ],
            [
                'code' => '4010-SUBSCRIPTION-REVENUE',
                'name' => 'Student Subscription Revenue',
                'type' => 'revenue',
                'description' => 'Earned revenue from student subscriptions',
            ],
            [
                'code' => '2020-REFUNDS-PAYABLE',
                'name' => 'Refunds & Reversals Payable',
                'type' => 'liability',
                'description' => 'Liability account for approved refunds and voided subscription receipts',
            ],
            [
                'code' => '5010-REFUNDS-REVERSALS',
                'name' => 'Subscription Refunds & Reversals',
                'type' => 'expense',
                'description' => 'Expense / contra-revenue account for refunded or voided subscription revenues',
            ],
        ];

        // Link payment accounts if present
        $meezanPaymentAccount = PaymentAccount::where('bank_name', 'like', '%Meezan%')->first();
        $jazzPaymentAccount = PaymentAccount::where('method', 'jazzcash')->orWhere('bank_name', 'like', '%Jazz%')->first();

        foreach ($defaults as $def) {
            $paymentAccountId = null;
            if ($def['code'] === '1020-BANK-MEEZAN' && $meezanPaymentAccount) {
                $paymentAccountId = $meezanPaymentAccount->id;
            } elseif ($def['code'] === '1030-WALLET-JAZZCASH' && $jazzPaymentAccount) {
                $paymentAccountId = $jazzPaymentAccount->id;
            }

            FinancialLedgerAccount::firstOrCreate(
                ['code' => $def['code']],
                [
                    'name' => $def['name'],
                    'type' => $def['type'],
                    'currency' => 'PKR',
                    'is_active' => true,
                    'payment_account_id' => $paymentAccountId,
                    'description' => $def['description'],
                ]
            );
        }
    }

    /**
     * Resolve the target debit asset account for a payment.
     */
    public function resolveAssetAccount(SubscriptionPaymentRequest $payment): FinancialLedgerAccount
    {
        $this->ensureDefaultAccounts();

        if ($payment->payment_method === 'teacher') {
            return FinancialLedgerAccount::where('code', '1040-TEACHER-CLEARING')->firstOrFail();
        }

        if ($payment->payment_account_id) {
            // Check direct mapping
            $acc = FinancialLedgerAccount::where('payment_account_id', $payment->payment_account_id)->first();
            if ($acc) {
                return $acc;
            }

            $paymentAcc = PaymentAccount::find($payment->payment_account_id);
            if ($paymentAcc) {
                if ($paymentAcc->method === 'jazzcash' || stripos($paymentAcc->bank_name, 'jazz') !== false) {
                    return FinancialLedgerAccount::where('code', '1030-WALLET-JAZZCASH')->firstOrFail();
                }
                if ($paymentAcc->method === 'bank_deposit' || stripos($paymentAcc->bank_name, 'meezan') !== false) {
                    return FinancialLedgerAccount::where('code', '1020-BANK-MEEZAN')->firstOrFail();
                }
            }
        }

        // Fallback to Bank Meezan or Cash
        return FinancialLedgerAccount::where('code', '1020-BANK-MEEZAN')->first()
            ?: FinancialLedgerAccount::where('code', '1010-CASH')->firstOrFail();
    }

    /**
     * Record a balanced double-entry transaction for an approved student subscription payment.
     */
    public function recordStudentPayment(
        SubscriptionPaymentRequest $payment,
        int $reviewerId,
        string $reviewerType = 'admin'
    ): FinancialTransaction {
        return DB::transaction(function () use ($payment, $reviewerId, $reviewerType) {
            $this->ensureDefaultAccounts();

            // Idempotency: verify no existing posted transaction for this request
            $existing = FinancialTransaction::where('reference_type', SubscriptionPaymentRequest::class)
                ->where('reference_id', $payment->id)
                ->first();

            if ($existing) {
                return $existing->load('entries.account');
            }

            $amount = (float) $payment->final_amount;
            if ($amount <= 0) {
                throw new RuntimeException("Payment amount must be greater than zero to post ledger entries.");
            }

            $debitAccount = $this->resolveAssetAccount($payment);

            // Determine if revenue should be deferred to 2010-UNEARNED-REVENUE (for multi-month prepaid terms)
            $payment->loadMissing('offeredProgram.offeredClass');
            $sessionEnd = $payment->offeredProgram?->offeredClass?->session_end;
            $sessionEndDate = $sessionEnd ? Carbon::parse($sessionEnd) : null;
            $isMultiMonth = $sessionEndDate && $sessionEndDate->isAfter(now()->endOfMonth());

            $creditAccountCode = $isMultiMonth ? '2010-UNEARNED-REVENUE' : '4010-SUBSCRIPTION-REVENUE';
            $creditAccount = FinancialLedgerAccount::where('code', $creditAccountCode)->firstOrFail();

            $dateSuffix = now()->format('Ym');
            $txNumber = sprintf('TXN-%s-%06d', $dateSuffix, $payment->id);

            $txType = $payment->payment_method === 'teacher'
                ? 'teacher_subscription_collection'
                : 'student_subscription_payment';

            $channelName = $payment->paymentAccount?->bank_name
                ?: ($payment->payment_method === 'teacher' ? 'Teacher Collection' : 'Direct Account');

            $transaction = FinancialTransaction::create([
                'transaction_number' => $txNumber,
                'transaction_type' => $txType,
                'reference_type' => SubscriptionPaymentRequest::class,
                'reference_id' => $payment->id,
                'transaction_date' => now(),
                'amount' => $amount,
                'currency' => 'PKR',
                'status' => 'posted',
                'notes' => "Payment #{$payment->id} approved by {$reviewerType} #{$reviewerId} for Student #{$payment->user_id}" . ($isMultiMonth ? " (Deferred unearned revenue)" : ""),
                'created_by' => $reviewerType === 'admin' ? $reviewerId : null,
            ]);

            // Debit Entry: Asset increases
            FinancialLedgerEntry::create([
                'financial_transaction_id' => $transaction->id,
                'financial_ledger_account_id' => $debitAccount->id,
                'entry_type' => 'debit',
                'amount' => $amount,
                'description' => "Subscription receipt via {$channelName} [Req #{$payment->id}]",
            ]);

            // Credit Entry: Revenue increases (or Deferred Liability increases)
            FinancialLedgerEntry::create([
                'financial_transaction_id' => $transaction->id,
                'financial_ledger_account_id' => $creditAccount->id,
                'entry_type' => 'credit',
                'amount' => $amount,
                'description' => ($isMultiMonth ? "Unearned Deferred Revenue" : "Subscription Revenue") . " - Student ID #{$payment->user_id} [Offering #{$payment->offered_program_id}]",
            ]);

            if (! $transaction->isBalanced()) {
                throw new RuntimeException("Accounting entry is not balanced for transaction {$txNumber}.");
            }

            return $transaction->load('entries.account');
        });
    }

    /**
     * Record a balanced double-entry transaction when an admin confirms a teacher settlement.
     */
    public function recordTeacherSettlement(TeacherSettlement $settlement, int $adminId): FinancialTransaction
    {
        return DB::transaction(function () use ($settlement, $adminId) {
            $this->ensureDefaultAccounts();

            $existing = FinancialTransaction::where('reference_type', TeacherSettlement::class)
                ->where('reference_id', $settlement->id)
                ->first();

            if ($existing) {
                return $existing->load('entries.account');
            }

            $amount = (float) $settlement->amount;
            if ($amount <= 0) {
                throw new RuntimeException("Settlement amount must be positive.");
            }

            // Debit Account: Target Bank / Wallet receiving settled funds
            $debitAccount = null;
            if ($settlement->payment_account_id) {
                $debitAccount = FinancialLedgerAccount::where('payment_account_id', $settlement->payment_account_id)->first();
            }

            if (! $debitAccount) {
                if ($settlement->payment_method === 'jazzcash') {
                    $debitAccount = FinancialLedgerAccount::where('code', '1030-WALLET-JAZZCASH')->firstOrFail();
                } elseif ($settlement->payment_method === 'bank') {
                    $debitAccount = FinancialLedgerAccount::where('code', '1020-BANK-MEEZAN')->firstOrFail();
                } else {
                    $debitAccount = FinancialLedgerAccount::where('code', '1010-CASH')->firstOrFail();
                }
            }

            // Credit Account: Teacher Collections Clearing Account (relieving teacher receivable)
            $creditAccount = FinancialLedgerAccount::where('code', '1040-TEACHER-CLEARING')->firstOrFail();

            $dateSuffix = now()->format('Ym');
            $txNumber = sprintf('TXN-SET-%s-%06d', $dateSuffix, $settlement->id);

            $transaction = FinancialTransaction::create([
                'transaction_number' => $txNumber,
                'transaction_type' => 'teacher_settlement',
                'reference_type' => TeacherSettlement::class,
                'reference_id' => $settlement->id,
                'transaction_date' => now(),
                'amount' => $amount,
                'currency' => 'PKR',
                'status' => 'posted',
                'notes' => "Teacher settlement #{$settlement->id} confirmed by Admin #{$adminId} for Teacher Profile #{$settlement->teacher_profile_id}",
                'created_by' => $adminId,
            ]);

            // Debit Entry: Bank / Wallet Asset increases
            FinancialLedgerEntry::create([
                'financial_transaction_id' => $transaction->id,
                'financial_ledger_account_id' => $debitAccount->id,
                'entry_type' => 'debit',
                'amount' => $amount,
                'description' => "Settlement receipt from Teacher #{$settlement->teacher_profile_id} via {$settlement->payment_method}",
            ]);

            // Credit Entry: Teacher Clearing Asset decreases
            FinancialLedgerEntry::create([
                'financial_transaction_id' => $transaction->id,
                'financial_ledger_account_id' => $creditAccount->id,
                'entry_type' => 'credit',
                'amount' => $amount,
                'description' => "Clearing of collected student subscriptions [Settlement #{$settlement->id}]",
            ]);

            if (! $transaction->isBalanced()) {
                throw new RuntimeException("Accounting entry is not balanced for settlement {$txNumber}.");
            }

            return $transaction->load('entries.account');
        });
    }

    /**
     * Get chart of accounts summary and ledger transaction history.
     */
    public function getLedgerOverview(array $filters = []): array
    {
        $this->ensureDefaultAccounts();

        $accounts = FinancialLedgerAccount::with(['entries'])->get()->map(function ($acc) {
            $totalDebit = (float) $acc->entries->where('entry_type', 'debit')->sum('amount');
            $totalCredit = (float) $acc->entries->where('entry_type', 'credit')->sum('amount');

            // Normal balance calculation
            $balance = in_array($acc->type, ['asset', 'expense'])
                ? ($totalDebit - $totalCredit)
                : ($totalCredit - $totalDebit);

            return [
                'id' => $acc->id,
                'code' => $acc->code,
                'name' => $acc->name,
                'type' => $acc->type,
                'currency' => $acc->currency,
                'is_active' => $acc->is_active,
                'description' => $acc->description,
                'total_debit' => round($totalDebit, 2),
                'total_credit' => round($totalCredit, 2),
                'balance' => round($balance, 2),
            ];
        });

        $query = FinancialTransaction::with(['entries.account', 'createdByUser:id,name,email'])
            ->orderByDesc('transaction_date')
            ->orderByDesc('id');

        if (! empty($filters['type'])) {
            $query->where('transaction_type', $filters['type']);
        }

        if (! empty($filters['start_date'])) {
            $query->whereDate('transaction_date', '>=', $filters['start_date']);
        }

        if (! empty($filters['end_date'])) {
            $query->whereDate('transaction_date', '<=', $filters['end_date']);
        }

        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('transaction_number', 'like', "%{$search}%")
                  ->orWhere('notes', 'like', "%{$search}%");
            });
        }

        $perPage = min((int) ($filters['per_page'] ?? 20), 100);
        $transactions = $query->paginate($perPage);

        $totalAssets = $accounts->where('type', 'asset')->sum('balance');
        $totalLiabilities = $accounts->where('type', 'liability')->sum('balance');
        $totalEquity = $accounts->where('type', 'equity')->sum('balance');
        $totalRevenue = $accounts->where('type', 'revenue')->sum('balance');
        $totalExpense = $accounts->where('type', 'expense')->sum('balance');

        $totalDebitsAll = (float) FinancialLedgerEntry::where('entry_type', 'debit')->sum('amount');
        $totalCreditsAll = (float) FinancialLedgerEntry::where('entry_type', 'credit')->sum('amount');
        $isBalanced = abs($totalDebitsAll - $totalCreditsAll) < 0.001;

        return [
            'accounts' => $accounts,
            'summary' => [
                'total_assets' => round($totalAssets, 2),
                'total_liabilities' => round($totalLiabilities, 2),
                'total_equity' => round($totalEquity, 2),
                'total_revenue' => round($totalRevenue, 2),
                'total_expense' => round($totalExpense, 2),
                'net_income' => round($totalRevenue - $totalExpense, 2),
                'total_debits' => round($totalDebitsAll, 2),
                'total_credits' => round($totalCreditsAll, 2),
                'is_balanced' => $isBalanced,
            ],
            'transactions' => $transactions,
        ];
    }

    /**
     * Recognize earned subscription revenue from unearned revenue for a period.
     */
    public function recognizeRevenue(
        int $adminId,
        ?float $amount = null,
        ?string $periodMonth = null,
        ?string $notes = null
    ): FinancialTransaction {
        return DB::transaction(function () use ($adminId, $amount, $periodMonth, $notes) {
            $this->ensureDefaultAccounts();

            $unearnedAccount = FinancialLedgerAccount::where('code', '2010-UNEARNED-REVENUE')->firstOrFail();
            $earnedAccount = FinancialLedgerAccount::where('code', '4010-SUBSCRIPTION-REVENUE')->firstOrFail();

            $totalDebit = (float) $unearnedAccount->entries()->where('entry_type', 'debit')->sum('amount');
            $totalCredit = (float) $unearnedAccount->entries()->where('entry_type', 'credit')->sum('amount');
            $availableUnearned = round($totalCredit - $totalDebit, 2);

            $periodMonth = $periodMonth ?: now()->format('Y-m');

            if ($amount === null) {
                $amount = $availableUnearned;
            }

            if ($amount <= 0) {
                throw new RuntimeException("No unearned subscription revenue is available for recognition (available: PKR {$availableUnearned}).");
            }

            if ($amount > $availableUnearned) {
                throw new RuntimeException("Recognition amount (PKR {$amount}) exceeds available unearned revenue balance (PKR {$availableUnearned}).");
            }

            $dateSuffix = now()->format('Ym');
            $txNumber = sprintf('TXN-REC-%s-%s', $dateSuffix, substr(uniqid(), -6));

            $transaction = FinancialTransaction::create([
                'transaction_number' => $txNumber,
                'transaction_type' => 'revenue_recognition',
                'reference_type' => FinancialLedgerAccount::class,
                'reference_id' => $unearnedAccount->id,
                'transaction_date' => now(),
                'amount' => $amount,
                'currency' => 'PKR',
                'status' => 'posted',
                'notes' => $notes ?: "Monthly subscription revenue recognition for period {$periodMonth} by Admin #{$adminId}",
                'created_by' => $adminId,
            ]);

            FinancialLedgerEntry::create([
                'financial_transaction_id' => $transaction->id,
                'financial_ledger_account_id' => $unearnedAccount->id,
                'entry_type' => 'debit',
                'amount' => $amount,
                'description' => "Amortization of deferred subscription revenue [Period {$periodMonth}]",
            ]);

            FinancialLedgerEntry::create([
                'financial_transaction_id' => $transaction->id,
                'financial_ledger_account_id' => $earnedAccount->id,
                'entry_type' => 'credit',
                'amount' => $amount,
                'description' => "Earned subscription revenue recognized for period {$periodMonth}",
            ]);

            if (! $transaction->isBalanced()) {
                throw new RuntimeException("Accounting entry is not balanced for revenue recognition {$txNumber}.");
            }

            return $transaction->load('entries.account');
        });
    }

    /**
     * Reverse a posted financial transaction with strict double-entry balancing and audit trail.
     */
    public function reverseTransaction(
        int $transactionId,
        int $adminId,
        string $reason
    ): FinancialTransaction {
        return DB::transaction(function () use ($transactionId, $adminId, $reason) {
            $this->ensureDefaultAccounts();

            $original = FinancialTransaction::with(['entries.account'])->lockForUpdate()->findOrFail($transactionId);

            if ($original->status === 'reversed') {
                throw new RuntimeException("Transaction #{$original->transaction_number} has already been reversed.");
            }

            if ($original->status !== 'posted') {
                throw new RuntimeException("Only posted transactions can be reversed (current status: {$original->status}).");
            }

            $dateSuffix = now()->format('Ym');
            $reversalNumber = sprintf('REV-%s-%06d', $dateSuffix, $original->id);

            $reversal = FinancialTransaction::create([
                'transaction_number' => $reversalNumber,
                'transaction_type' => 'transaction_reversal',
                'reference_type' => FinancialTransaction::class,
                'reference_id' => $original->id,
                'transaction_date' => now(),
                'amount' => $original->amount,
                'currency' => $original->currency ?? 'PKR',
                'status' => 'posted',
                'notes' => "Reversal of {$original->transaction_number}: {$reason} (Authorized by Admin #{$adminId})",
                'created_by' => $adminId,
            ]);

            foreach ($original->entries as $entry) {
                $oppositeType = $entry->entry_type === 'debit' ? 'credit' : 'debit';

                FinancialLedgerEntry::create([
                    'financial_transaction_id' => $reversal->id,
                    'financial_ledger_account_id' => $entry->financial_ledger_account_id,
                    'entry_type' => $oppositeType,
                    'amount' => $entry->amount,
                    'description' => "Reversal of entry #{$entry->id} [{$entry->account?->name}]: {$reason}",
                ]);
            }

            if (! $reversal->isBalanced()) {
                throw new RuntimeException("Reversal transaction {$reversalNumber} is not balanced.");
            }

            $original->update([
                'status' => 'reversed',
                'notes' => trim($original->notes . " | REVERSED via {$reversalNumber} by Admin #{$adminId}: {$reason}"),
            ]);

            return $reversal->load('entries.account');
        });
    }
}
