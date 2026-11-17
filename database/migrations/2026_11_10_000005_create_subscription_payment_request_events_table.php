<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('subscription_payment_request_events')) {
            Schema::create('subscription_payment_request_events', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('subscription_payment_request_id');
                $table->string('event_type', 24);
                $table->string('reviewer_type', 16)->nullable();
                $table->unsignedBigInteger('reviewer_id')->nullable();
                $table->string('previous_status', 16)->nullable();
                $table->string('new_status', 16)->nullable();
                $table->string('reason', 1000)->nullable();
                $table->json('details')->nullable();
                $table->timestamp('created_at')->useCurrent();
            });
        }

        $requiredColumns = ['id', 'subscription_payment_request_id', 'event_type', 'reviewer_type', 'reviewer_id',
            'previous_status', 'new_status', 'reason', 'details', 'created_at'];
        if (!Schema::hasColumns('subscription_payment_request_events', $requiredColumns)) {
            throw new \RuntimeException('subscription_payment_request_events exists with an incomplete column set; inspect and repair it before retrying this migration.');
        }
        if (!Schema::hasTable('subscription_payment_requests')) {
            throw new \RuntimeException('subscription_payment_requests is required before creating payment review history.');
        }

        $indexes = collect(Schema::getIndexes('subscription_payment_request_events'))->pluck('name');
        if (!$indexes->contains('payment_request_events_request_created_idx')) {
            Schema::table('subscription_payment_request_events', fn (Blueprint $table) =>
                $table->index(['subscription_payment_request_id', 'created_at'], 'payment_request_events_request_created_idx'));
        }
        if (!$indexes->contains('payment_request_events_reviewer_idx')) {
            Schema::table('subscription_payment_request_events', fn (Blueprint $table) =>
                $table->index(['reviewer_type', 'reviewer_id', 'created_at'], 'payment_request_events_reviewer_idx'));
        }

        $foreignKeys = collect(Schema::getForeignKeys('subscription_payment_request_events'));
        if (!$foreignKeys->contains(fn ($key) => $key['columns'] === ['subscription_payment_request_id']
            && $key['foreign_table'] === 'subscription_payment_requests')) {
            Schema::table('subscription_payment_request_events', fn (Blueprint $table) =>
                $table->foreign('subscription_payment_request_id', 'spr_events_request_fk')
                    ->references('id')->on('subscription_payment_requests')->cascadeOnDelete());
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_payment_request_events');
    }
};
