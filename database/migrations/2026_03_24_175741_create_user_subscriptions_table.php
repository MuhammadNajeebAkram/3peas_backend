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
        Schema::create('user_subscriptions', function (Blueprint $table) { 
            $table->id();
            $table->foreignId('user_id')->constrained('web_users')->cascadeOnDelete();
            $table->foreignId('offered_program_id')->constrained('offered_programs')->cascadeOnDelete();
            $table->enum('status', ['pending', 'active', 'expired', 'rejected', 'cancelled'])->default('pending');
            $table->enum('access_type', ['paid', 'discounted', 'free_specimen', 'complimentary'])
        ->default('paid');
        $table->decimal('price_paid', 10, 2)->default(0);
            $table->date('started_at')->nullable();
            $table->date('expires_at')->nullable();
            $table->date('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->noActionOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_subscriptions');
    }
};
