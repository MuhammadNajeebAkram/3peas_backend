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
        Schema::create('subscription_payment_requests', function (Blueprint $table) { 
            $table->id();
            $table->foreignId('user_id')->constrained('web_users')->cascadeOnDelete();
            $table->foreignId('offered_program_id')->constrained('offered_programs')->cascadeOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained('user_subscriptions')->cascadeOnDelete();
            $table->foreignId('payment_account_id')->constrained('payment_accounts')->cascadeOnDelete();
            $table->decimal('price', 10, 2)->default(0);
            $table->decimal('discount_amount', 10, 2)->default(0);
            $table->decimal('discount_percentage', 5, 2)->default(0);
            $table->decimal('final_amount', 10, 2)->default(0);
            $table->string('transaction_id')->nullable()->unique();
            //$table->check("(payment_method = 'cash' AND transaction_id IS NULL) OR (payment_method = 'bank' AND transaction_id IS NOT NULL)");
            $table->string('payer_name')->nullable();
            $table->string('payer_phone')->nullable();
            $table->string('proof_file_path')->nullable();
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->date('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->noActionOnDelete();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->noActionOnDelete();
            $table->string('rejection_reason')->nullable();
            $table->string('admin_remarks')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);           
            $table->index(['offered_program_id', 'status']);
            $table->index(['user_id', 'offered_program_id']);            
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscription_payment_requests');
    }
};
