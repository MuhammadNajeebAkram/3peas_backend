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
        Schema::create('freemium_campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->enum('status', ['draft', 'active', 'paused', 'archived'])->default('draft');
            $table->boolean('is_stackable')->default(false)->comment('0: Isolated top-priority, 1: Additive rule/quota participation');
            $table->unsignedInteger('priority')->default(100)->comment('Lower number = higher precedence');
            $table->timestamp('start_at');
            $table->timestamp('end_at');
            $table->json('eligibility_criteria')->nullable()->comment('Eligibility rules (board, class, signup date ranges, pilot tags)');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->softDeletes();

            $table->index(['status', 'start_at', 'end_at'], 'idx_fc_status_dates');
            $table->index('priority', 'idx_fc_priority');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('freemium_campaigns');
    }
};
