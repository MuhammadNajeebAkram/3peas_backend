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
        Schema::table('web_users', function (Blueprint $table) {
            //
            $table->string('phone')->unique()->nullable();
            $table->string('google_id')->unique()->nullable();
            $table->enum('login_provider', ['email', 'google'])->default('email');
            $table->string('avatar')->nullable();
            $table->enum('status', ['active', 'blocked'])->default('active');
            $table->dateTime('last_login_at')->nullable();
            $table->dateTime('deleted_at')->nullable();

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('web_users', function (Blueprint $table) {
            //
            $table->dropColumn('phone');
            $table->dropColumn('google_id');
            $table->dropColumn('login_provider');
            $table->dropColumn('avatar');
            $table->dropColumn('status');
            $table->dropColumn('last_login_at');
            $table->dropColumn('deleted_at');
        });
    }
};
