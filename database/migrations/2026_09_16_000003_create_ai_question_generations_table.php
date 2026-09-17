<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_question_generations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('ai_request_id')->nullable()->constrained('ai_requests')->nullOnDelete();
            $table->string('status', 20)->default('pending');
            $table->json('context');
            $table->json('sources');
            $table->longText('instructions')->nullable();
            $table->json('draft')->nullable();
            $table->json('saved_questions')->nullable();
            $table->foreignId('scenario_group_id')->nullable()->constrained('question_scenario_groups_tbl')->nullOnDelete();
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_question_generations');
    }
};
