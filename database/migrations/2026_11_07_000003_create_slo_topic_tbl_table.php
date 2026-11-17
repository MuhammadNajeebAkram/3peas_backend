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
        Schema::create('slo_topic_tbl', function (Blueprint $table) {
            $table->id();
            $table->foreignId('slo_domain_id')->constrained('slo_domain_tbl')->cascadeOnDelete();
            $table->foreignId('class_id')->constrained('class_tbl')->cascadeOnDelete();
            $table->string('slo_code', 100); // e.g. B-11-B-01, CS-09-A-01
            $table->text('slo_title'); // Full outcome statement
            $table->text('slo_title_um')->nullable(); // Urdu / Unicode translation
            $table->text('benchmark_text')->nullable(); // e.g. Benchmark 1: Critically analyze...
            $table->text('description')->nullable(); // Sub-skills, bullet points, laboratory procedures
            $table->enum('assessment_type', ['summative', 'formative', 'summative_pba', 'formative_pba'])->default('summative');
            $table->enum('cognitive_level', ['K', 'U', 'A', 'P'])->default('U'); // Knowledge, Understanding, Application, Psychomotor
            $table->boolean('is_practical')->default(false);
            $table->boolean('is_optional')->default(false);
            $table->boolean('is_alp')->default(true);
            $table->text('remarks')->nullable(); // e.g. Question(s) will be asked in annual exam
            $table->integer('sort_order')->default(0);
            $table->boolean('activate')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('slo_code', 'idx_slo_topic_code');
            $table->index(['slo_domain_id', 'class_id'], 'idx_slo_topic_domain_class');
            $table->index('assessment_type');
            $table->index('cognitive_level');
            $table->index('activate');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('slo_topic_tbl');
    }
};
