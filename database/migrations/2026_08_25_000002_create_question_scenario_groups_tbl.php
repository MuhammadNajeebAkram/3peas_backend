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
        Schema::create('question_scenario_groups_tbl', function (Blueprint $table) {
            $table->id();
            $table->string('title')->nullable();
            $table->longText('scenario_text');
            $table->longText('scenario_text_um')->nullable();
            $table->string('scenario_image')->nullable();
            $table->foreignId('question_presentation_type_id')->nullable();
            $table->foreignId('topic_id')->nullable();
            $table->foreignId('unit_id')->nullable();
            $table->foreignId('book_id')->nullable();
            $table->boolean('activate')->default(true);
            $table->foreignId('created_by')->nullable();
            $table->foreignId('updated_by')->nullable();
            $table->timestamps();

            $table->foreign('question_presentation_type_id', 'qsg_presentation_type_fk')
                ->references('id')
                ->on('question_presentation_type_tbl')
                ->nullOnDelete();
            $table->foreign('topic_id', 'qsg_topic_fk')
                ->references('id')
                ->on('book_unit_topic_tbl')
                ->nullOnDelete();
            $table->foreign('unit_id', 'qsg_unit_fk')
                ->references('id')
                ->on('book_unit_tbl')
                ->nullOnDelete();
            $table->foreign('book_id', 'qsg_book_fk')
                ->references('id')
                ->on('book_tbl')
                ->nullOnDelete();
            $table->foreign('created_by', 'qsg_created_by_fk')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
            $table->foreign('updated_by', 'qsg_updated_by_fk')
                ->references('id')
                ->on('users')
                ->nullOnDelete();

            $table->index(['book_id', 'unit_id', 'topic_id'], 'idx_scenario_groups_hierarchy');
            $table->index('question_presentation_type_id', 'idx_scenario_groups_presentation_type');
            $table->index('activate', 'idx_scenario_groups_activate');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('question_scenario_groups_tbl');
    }
};
