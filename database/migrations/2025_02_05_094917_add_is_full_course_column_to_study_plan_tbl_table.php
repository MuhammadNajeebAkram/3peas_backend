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
        Schema::table('study_plan_tbl', function (Blueprint $table) {
            //
            $table->boolean('is_full_course')->after('plan_for');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('study_plan_tbl', function (Blueprint $table) {
            //
            $table->dropColumn('is_full_course');
        });
    }
};
