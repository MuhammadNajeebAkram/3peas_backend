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
        Schema::create('data_entry_assignment_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assignment_id')->constrained('data_entry_assignments')->cascadeOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('module_type', 50);
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('title')->nullable();
            $table->decimal('unit_count', 10, 2)->default(1);
            $table->string('status', 30)->default('pending_review');
            $table->text('submitter_notes')->nullable();
            $table->text('reviewer_remarks')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('assignment_id');
            $table->index('submitted_by');
            $table->index('module_type');
            $table->index(['module_type', 'reference_id']);
            $table->index('status');
            $table->index('reviewed_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('data_entry_assignment_items');
    }
};
