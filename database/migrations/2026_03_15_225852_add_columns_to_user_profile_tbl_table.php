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
            //
          /*  $table->unsignedBigInteger('city_id')->nullable()->change();
            $table->unsignedBigInteger('institute_id')->nullable()->change();

            $table->unsignedBigInteger('referral_code')->nullable();
            $table->boolean('profile_completed')->default(false);
            $table->enum('preferred_language', ['en', 'ur'])->default('en');*/

           
           // $table->dropForeign(['class_id']);
           // $table->dropForeign(['curriculum_board_id']);
           // $table->dropForeign(['study_group_id']);
          //  $table->dropForeign(['study_plan_id']);

           // $table->dropColumn('phone');
           // $table->dropColumn('class_id');
           // $table->dropColumn('curriculum_board_id');
           // $table->dropColumn('incharge_name');
          //  $table->dropColumn('incharge_phone');
           // $table->dropColumn('avatar');
           // $table->dropColumn('phone');
           $table->dropColumn('activate');
            $table->dropColumn('study_group_id');
            $table->dropColumn('study_plan_id');

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
