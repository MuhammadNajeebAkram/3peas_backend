<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teacher_subscription_plan_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('teacher_subscription_plan_id')
                ->constrained('teacher_subscription_plans', 'id', 'teacher_sub_plan_history_plan_fk')
                ->restrictOnDelete();
            $table->foreignId('admin_user_id')->nullable()
                ->constrained('users', 'id', 'teacher_sub_plan_history_admin_fk')
                ->nullOnDelete();
            $table->string('event_type', 48);
            $table->json('previous_values')->nullable();
            $table->json('new_values')->nullable();
            $table->text('reason')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['teacher_subscription_plan_id', 'created_at'], 'teacher_sub_plan_history_plan_idx');
            $table->index(['admin_user_id', 'created_at'], 'teacher_sub_plan_history_admin_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teacher_subscription_plan_history');
    }
};
