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
        Schema::create('data_entry_assignment_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assignment_id')->constrained('data_entry_assignments')->cascadeOnDelete();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('approved_units', 10, 2)->default(0);
            $table->decimal('payable_amount', 12, 2)->default(0);
            $table->decimal('paid_amount', 12, 2)->default(0);
            $table->string('payment_status', 30)->default('unpaid');
            $table->string('payment_method', 50)->nullable();
            $table->string('transaction_reference')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index('assignment_id');
            $table->index('paid_by');
            $table->index('payment_status');
            $table->index('paid_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('data_entry_assignment_payments');
    }
};
