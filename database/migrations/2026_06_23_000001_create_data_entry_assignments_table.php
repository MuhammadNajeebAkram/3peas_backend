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
        Schema::create('data_entry_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assigned_to')->constrained('users')->cascadeOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('module_type', 50)->default('question');
            $table->string('title');
            $table->text('instructions')->nullable();
            $table->unsignedInteger('target_quantity')->nullable();
            $table->string('payment_type', 30)->default('per_question');
            $table->decimal('rate_per_unit', 12, 2)->default(0);
            $table->decimal('fixed_amount', 12, 2)->default(0);
            $table->string('status', 30)->default('assigned');
            $table->date('due_date')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('assigned_to');
            $table->index('assigned_by');
            $table->index('module_type');
            $table->index('payment_type');
            $table->index('status');
            $table->index('due_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('data_entry_assignments');
    }
};
