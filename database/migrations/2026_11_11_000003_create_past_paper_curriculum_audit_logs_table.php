<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('past_paper_curriculum_audit_logs')) {
            Schema::create('past_paper_curriculum_audit_logs', function (Blueprint $table) {
                $table->id();
                $table->string('paper_source', 32);
                $table->unsignedBigInteger('paper_id');
                $table->string('operation_type', 32);
                $table->unsignedBigInteger('previous_curriculum_board_id')->nullable();
                $table->unsignedBigInteger('new_curriculum_board_id');
                $table->unsignedBigInteger('admin_user_id')->nullable();
                $table->string('note', 500)->nullable();
                $table->string('ip_address', 45)->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->index(['paper_source', 'paper_id', 'created_at'], 'pp_curr_audit_source_paper_idx');
                $table->index(['admin_user_id', 'created_at'], 'pp_curr_audit_admin_idx');
                $table->index(['new_curriculum_board_id', 'created_at'], 'pp_curr_audit_board_idx');

                $table->foreign('previous_curriculum_board_id', 'pp_curr_audit_prev_cb_fk')
                    ->references('id')->on('curriculum_board_tbl')->nullOnDelete();
                $table->foreign('new_curriculum_board_id', 'pp_curr_audit_new_cb_fk')
                    ->references('id')->on('curriculum_board_tbl')->restrictOnDelete();
                $table->foreign('admin_user_id', 'pp_curr_audit_admin_fk')
                    ->references('id')->on('users')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('past_paper_curriculum_audit_logs');
    }
};
