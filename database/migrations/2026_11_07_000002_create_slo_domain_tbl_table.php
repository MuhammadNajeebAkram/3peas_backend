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
        Schema::create('slo_domain_tbl', function (Blueprint $table) {
            $table->id();
            $table->foreignId('curriculum_board_id')->nullable()->constrained('curriculum_board_tbl')->nullOnDelete();
            $table->foreignId('subject_id')->constrained('subject_tbl')->cascadeOnDelete();
            $table->string('domain_code', 20); // e.g. A, B, C, D
            $table->string('domain_name', 255); // e.g. Nature of Science in Biology, Molecular Biology
            $table->string('domain_name_um', 255)->nullable();
            $table->text('standard_statement')->nullable(); // Overarching standard description
            $table->unsignedSmallInteger('total_periods')->nullable(); // Teaching periods required (e.g. 15, 26)
            $table->integer('sort_order')->default(0);
            $table->boolean('activate')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['subject_id', 'domain_code'], 'idx_slo_domain_subj_code');
            $table->index('activate');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('slo_domain_tbl');
    }
};
