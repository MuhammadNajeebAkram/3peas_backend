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
        Schema::create('contact_inquiries', function (Blueprint $table) {
            $table->id();
            $table->string('type', 30)->default('general'); // publishing, general, suggestion, complaint
            $table->string('full_name', 191);
            $table->string('email', 191);
            $table->string('phone', 50)->nullable();
            $table->string('organization', 191)->nullable();
            $table->string('subject', 255)->nullable();
            $table->text('message');
            $table->string('status', 30)->default('pending'); // pending, under_review, resolved, archived
            $table->text('admin_notes')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();

            $table->index('type');
            $table->index('status');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('contact_inquiries');
    }
};
