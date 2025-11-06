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
        Schema::table('model_papers', function (Blueprint $table) {
            //
            $table->unsignedBigInteger('curriculum_board_id')->nullable();
            $table->foreign('curriculum_board_id')->references('id')->on('curriculum_board_tbl')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('model_papers', function (Blueprint $table) {
            //
            $table->dropForeign('curriculum_board_id');
            $table->dropColumn('curriculum_board_id');
        });
    }
};
