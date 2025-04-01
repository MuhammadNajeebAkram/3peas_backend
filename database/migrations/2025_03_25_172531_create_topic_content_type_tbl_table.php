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
        Schema::create('topic_content_type_tbl', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('name_um')->nullable();
            $table->boolean('has_child')->default(false);
            $table -> tinyInteger('is_mcq')->default(0);
            $table->boolean('activate')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('topic_content_type_tbl');
    }
};
