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
        Schema::create('freemium_student_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained('freemium_campaigns')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('web_users')->restrictOnDelete()->comment('References web_users(id)');

            $table->enum('grant_type', ['direct', 'voucher', 'pilot'])->default('direct');
            $table->string('voucher_code', 100)->nullable();
            $table->timestamp('granted_at')->useCurrent();
            $table->timestamp('expires_at')->nullable();
            $table->enum('status', ['active', 'revoked', 'expired'])->default('active');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->softDeletes();

            $table->unique(['campaign_id', 'user_id'], 'uk_campaign_user');
            $table->index(['user_id', 'status', 'expires_at'], 'idx_fsg_user_grant_status');
            $table->index(['campaign_id', 'user_id'], 'idx_fsg_campaign_user');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('freemium_student_grants');
    }
};
