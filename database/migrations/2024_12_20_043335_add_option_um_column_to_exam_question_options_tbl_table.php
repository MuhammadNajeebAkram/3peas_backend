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
        Schema::table('exam_question_options_tbl', function (Blueprint $table) {
            //
            $table -> string('option_um');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('exam_question_options_tbl', function (Blueprint $table) {
            //
            $table -> dropColumn('option_um');
        });
    }
};
