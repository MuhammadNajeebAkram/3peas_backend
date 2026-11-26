<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('web_user_role_changes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('web_user_id');
            $table->string('from_role', 32);
            $table->string('to_role', 32);
            $table->unsignedBigInteger('changed_by_admin_id')->nullable();
            $table->text('reason');
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['web_user_id', 'created_at']);
            $table->index('changed_by_admin_id');

            $table->foreign('web_user_id')->references('id')->on('web_users')->restrictOnDelete();
            $table->foreign('changed_by_admin_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('web_user_role_changes');
    }
};
