<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Drop if partially created due to error
        Schema::dropIfExists('payment_receipts');
        Schema::dropIfExists('financial_ledger_entries');
        Schema::dropIfExists('financial_transactions');
        Schema::dropIfExists('financial_ledger_accounts');

        // 1. Financial Ledger Accounts (Chart of Accounts for LMS / Subscriptions)
        Schema::create('financial_ledger_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name', 150);
            $table->enum('type', ['asset', 'liability', 'equity', 'revenue', 'expense']);
            $table->string('currency', 10)->default('PKR');
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('payment_account_id')->nullable()->index();
            $table->text('description')->nullable();
            $table->timestamps();
        });

        // 2. Financial Transactions (Journal Entry Headers)
        Schema::create('financial_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('transaction_number', 50)->unique();
            $table->string('transaction_type', 50); // student_subscription_payment, teacher_subscription_collection, teacher_settlement, refund, adjustment
            $table->string('reference_type', 100)->nullable(); // subscription_payment_request, teacher_settlement
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->dateTime('transaction_date');
            $table->decimal('amount', 12, 2);
            $table->string('currency', 10)->default('PKR');
            $table->enum('status', ['draft', 'posted', 'reversed'])->default('posted');
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['reference_type', 'reference_id'], 'fin_tx_ref_index');
            $table->index('transaction_date');
            $table->index('status');
        });

        // 3. Financial Ledger Entries (Double-Entry Debit / Credit Line Items)
        Schema::create('financial_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('financial_transaction_id')
                ->constrained('financial_transactions')
                ->cascadeOnDelete();
            $table->foreignId('financial_ledger_account_id')
                ->constrained('financial_ledger_accounts')
                ->restrictOnDelete();
            $table->enum('entry_type', ['debit', 'credit']);
            $table->decimal('amount', 12, 2);
            $table->string('description', 255)->nullable();
            $table->timestamps();

            $table->index(['financial_transaction_id', 'entry_type'], 'fin_entry_tx_type_index');
            $table->index('financial_ledger_account_id');
        });

        // 4. Payment Receipts
        Schema::create('payment_receipts', function (Blueprint $table) {
            $table->id();
            $table->string('receipt_number', 50)->unique();
            $table->foreignId('subscription_payment_request_id')
                ->constrained('subscription_payment_requests')
                ->cascadeOnDelete();
            $table->foreignId('financial_transaction_id')
                ->nullable()
                ->constrained('financial_transactions')
                ->nullOnDelete();
            $table->unsignedBigInteger('user_id')->index();
            $table->decimal('amount', 12, 2);
            $table->string('currency', 10)->default('PKR');
            $table->string('payment_method', 50); // bank_deposit, jazzcash, teacher_collection
            $table->string('payment_channel', 100)->nullable(); // Meezan Bank, JazzCash, etc.
            $table->string('transaction_reference', 100)->nullable();
            $table->dateTime('issued_at');
            $table->string('pdf_path', 255)->nullable();
            $table->string('email_sent_to', 150)->nullable();
            $table->dateTime('email_sent_at')->nullable();
            $table->enum('email_status', ['pending', 'sent', 'failed'])->default('pending');
            $table->enum('status', ['issued', 'voided'])->default('issued');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('issued_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_receipts');
        Schema::dropIfExists('financial_ledger_entries');
        Schema::dropIfExists('financial_transactions');
        Schema::dropIfExists('financial_ledger_accounts');
    }
};
