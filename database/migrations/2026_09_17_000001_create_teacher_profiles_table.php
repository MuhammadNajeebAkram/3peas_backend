<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teacher_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('web_user_id')->unique()->constrained('web_users')->restrictOnDelete();
            $table->foreignId('institute_id')->nullable()->constrained('institute_tbl')->restrictOnDelete();
            $table->foreignId('city_id')->nullable()->constrained('city_tbl')->restrictOnDelete();
            $table->string('teacher_code', 32)->unique();
            $table->string('collection_code', 32)->nullable()->unique();
            $table->enum('status', ['pending', 'active', 'rejected', 'suspended'])->default('pending')->index();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('status_changed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('status_changed_at')->nullable();
            $table->text('admin_note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teacher_profiles');
    }
};
