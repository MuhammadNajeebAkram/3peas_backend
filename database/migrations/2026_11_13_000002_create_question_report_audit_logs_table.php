<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('question_report_audit_logs')) {
            Schema::create('question_report_audit_logs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('question_report_id');
                $table->unsignedBigInteger('admin_user_id');
                $table->string('previous_status', 20)->nullable();
                $table->string('new_status', 20)->nullable();
                $table->text('internal_note')->nullable();
                $table->string('action_type', 30)->default('status_change'); // status_change, note_added, bulk_status_change
                $table->timestamp('created_at')->useCurrent();

                $table->index('question_report_id');
                $table->index('admin_user_id');
                $table->index(['question_report_id', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('question_report_audit_logs');
    }
};
