<?php

namespace App\Console\Commands;

use App\Http\Services\FinancialLedgerService;
use Illuminate\Console\Command;
use Throwable;

class RecognizeRevenueCommand extends Command
{
    protected $signature = 'finance:recognize-revenue 
                            {--amount= : Custom amount in PKR to recognize} 
                            {--period= : Recognition period month (e.g. 2026-11)} 
                            {--notes= : Audit notes for the recognition transaction}';

    protected $description = 'Recognize earned subscription revenue from unearned deferred revenue (debit Unearned Revenue, credit Earned Revenue)';

    public function handle(FinancialLedgerService $ledgerService): int
    {
        $amount = $this->option('amount') !== null ? (float) $this->option('amount') : null;
        $period = $this->option('period') ?: now()->format('Y-m');
        $notes = $this->option('notes');

        $this->info("Initiating revenue recognition for period [{$period}]...");

        try {
            $transaction = $ledgerService->recognizeRevenue(
                0, // System user ID
                $amount,
                $period,
                $notes ?: "Automated monthly subscription revenue recognition for {$period}"
            );

            $this->info("✓ Revenue successfully recognized!");
            $this->table(
                ['Transaction #', 'Type', 'Amount (PKR)', 'Status', 'Date'],
                [[
                    $transaction->transaction_number,
                    $transaction->transaction_type,
                    number_format($transaction->amount, 2),
                    $transaction->status,
                    $transaction->transaction_date,
                ]]
            );

            return Command::SUCCESS;
        } catch (Throwable $e) {
            $this->error("Failed to recognize revenue: " . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
