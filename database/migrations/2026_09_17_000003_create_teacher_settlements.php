<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Existing teacher accounts receive a pending profile without changing their login or student history.
        DB::table('web_users')->where('role', 'teacher')->whereNull('deleted_at')
            ->whereNotIn('id', DB::table('teacher_profiles')->select('web_user_id'))
            ->orderBy('id')->each(function ($user) {
                DB::table('teacher_profiles')->insert(['web_user_id' => $user->id,
                    'teacher_code' => 'TCH-'.strtoupper(\Illuminate\Support\Str::random(12)),
                    'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
            });
        Schema::create('teacher_profile_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('teacher_profile_id')->constrained()->restrictOnDelete();
            $table->foreignId('admin_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('action', 40);
            $table->string('collection_code', 32)->nullable()->unique();
            $table->json('details')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
        DB::table('teacher_profiles')->whereNotNull('collection_code')->orderBy('id')->each(function ($profile) {
            DB::table('teacher_profile_events')->insert(['teacher_profile_id' => $profile->id,
                'action' => 'existing_code', 'collection_code' => $profile->collection_code, 'created_at' => now()]);
        });
        Schema::create('teacher_settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('teacher_profile_id')->constrained()->restrictOnDelete();
            $table->uuid('submission_key');
            $table->decimal('amount', 12, 2);
            $table->char('currency', 3)->default('PKR');
            $table->enum('payment_method', ['cash', 'bank', 'jazzcash']);
            $table->foreignId('payment_account_id')->nullable()->constrained('payment_accounts')->restrictOnDelete();
            $table->string('transaction_reference', 100)->nullable();
            $table->unique(['payment_account_id', 'transaction_reference'], 'teacher_settlement_transfer_unique');
            $table->date('paid_at');
            $table->string('proof_path')->nullable();
            $table->enum('status', ['pending', 'confirmed', 'rejected'])->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('rejection_reason', 1000)->nullable();
            $table->string('notes', 1000)->nullable();
            $table->timestamps();
            $table->unique(['teacher_profile_id', 'submission_key']);
            $table->index(['teacher_profile_id', 'status']);
        });
        Schema::create('teacher_settlement_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('teacher_settlement_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_request_id')->constrained('subscription_payment_requests')->restrictOnDelete();
            $table->decimal('amount', 12, 2);
            $table->timestamps();
            $table->unique(['teacher_settlement_id', 'payment_request_id'], 'teacher_allocation_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teacher_settlement_allocations');
        Schema::dropIfExists('teacher_settlements');
        Schema::dropIfExists('teacher_profile_events');
    }
};
