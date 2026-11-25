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
        Schema::table('payment_receipts', function (Blueprint $table) {
            // Guarantee 1:1 receipt per payment request at database constraint level
            $table->unique('subscription_payment_request_id', 'uniq_receipt_payment_request');
        });

        Schema::table('financial_transactions', function (Blueprint $table) {
            // Guarantee unique posted transaction per source reference
            $table->unique(['reference_type', 'reference_id', 'transaction_type'], 'uniq_fin_tx_ref_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payment_receipts', function (Blueprint $table) {
            $table->dropUnique('uniq_receipt_payment_request');
        });

        Schema::table('financial_transactions', function (Blueprint $table) {
            $table->dropUnique('uniq_fin_tx_ref_type');
        });
    }
};
