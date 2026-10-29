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
        Schema::table('user_profile_tbl', function (Blueprint $table) {
            if (Schema::hasColumn('user_profile_tbl', 'activate')) {
                $table->dropColumn('activate');
            }
            if (Schema::hasColumn('user_profile_tbl', 'study_group_id')) {
                $table->dropColumn('study_group_id');
            }
            if (Schema::hasColumn('user_profile_tbl', 'study_plan_id')) {
                $table->dropColumn('study_plan_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_profile_tbl', function (Blueprint $table) {
            //
        });
    }
};
