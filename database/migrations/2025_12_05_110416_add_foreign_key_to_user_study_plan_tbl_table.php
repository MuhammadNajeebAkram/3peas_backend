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
        Schema::table('user_study_plan_tbl', function (Blueprint $table) {
            //
            $table->unsignedBigInteger('user_id')->change();
            $table->foreign('user_id')->references('id')->on('web_users')->onDelete('cascade');

            $table->unsignedBigInteger('study_plan_id')->change();
            $table->foreign('study_plan_id')->references('id')->on('study_plan_tbl')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_study_plan_tbl', function (Blueprint $table) {
            //
            $table->dropForeign('user_id');
            $table->dropForeign('study_plan_id');
        });
    }
};
