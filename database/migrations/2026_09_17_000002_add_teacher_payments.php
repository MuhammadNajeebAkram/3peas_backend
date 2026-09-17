<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_payment_requests', function (Blueprint $table) {
            $table->unsignedBigInteger('payment_account_id')->nullable()->change();
            // Existing payments retain their account-based method.
            $table->string('payment_method', 20)->default('account');
            $table->foreignId('teacher_profile_id')->nullable()->constrained('teacher_profiles')->restrictOnDelete();
            $table->string('collection_code_snapshot', 32)->nullable();
            $table->foreignId('confirmed_by_web_user_id')->nullable()->constrained('web_users')->restrictOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignId('rejected_by_web_user_id')->nullable()->constrained('web_users')->restrictOnDelete();
            $table->string('receipt_number', 50)->nullable()->unique();
            $table->index(['teacher_profile_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('subscription_payment_requests', function (Blueprint $table) {
            $table->dropIndex(['teacher_profile_id', 'status']);
            $table->dropConstrainedForeignId('teacher_profile_id');
            $table->dropConstrainedForeignId('confirmed_by_web_user_id');
            $table->dropConstrainedForeignId('rejected_by_web_user_id');
            $table->dropUnique(['receipt_number']);
            $table->dropColumn(['payment_method', 'collection_code_snapshot', 'confirmed_at', 'receipt_number']);
        });
        // Keep payment_account_id nullable: teacher payment history has no bank account.
    }
};
