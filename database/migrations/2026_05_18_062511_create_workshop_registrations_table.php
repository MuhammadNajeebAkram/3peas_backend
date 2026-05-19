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
        Schema::create('workshop_registrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workshop_id')
                ->constrained('workshops')
                ->cascadeOnDelete();

            $table->foreignId('user_id')
                ->constrained('web_users')
                ->cascadeOnDelete();

            $table->enum('status', ['registered', 'attended', 'missed', 'cancelled'])
                ->default('registered');

            $table->dateTime('registered_at')->nullable();
            $table->dateTime('attendance_marked_at')->nullable();

            $table->foreignId('attendance_marked_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            $table->unique(['workshop_id', 'user_id'], 'wrk_reg_unique');
            $table->index(['user_id', 'status'], 'wrk_reg_user_status_idx');
            $table->index(['workshop_id', 'status'], 'wrk_reg_workshop_status_idx');
            $table->index('registered_at', 'wrk_reg_time_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('workshop_registrations');
    }
};
