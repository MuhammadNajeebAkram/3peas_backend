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
            $table->foreignId('study_group_id')->nullable()->constrained('study_group_tbl', 'id')->onDelete('no action');    
            
           /* $table->unsignedBigInteger('study_group_id');
            $table->foreign('study_group_id')->references('id')->on('study_group_tbl')->onDelete('no action');*/

            $table->unsignedBigInteger('user_id')->change();
            $table->foreign('user_id')->references('id')->on('web_users')->onDelete('cascade');

            $table->unsignedBigInteger('class_id')->nullable()->change();
            $table->foreign('class_id')->references('id')->on('class_tbl')->onDelete('no action');

            $table->unsignedBigInteger('curriculum_board_id')->nullable()->change();
            $table->foreign('curriculum_board_id')->references('id')->on('curriculum_board_tbl')->onDelete('no action');

            $table->unsignedBigInteger('city_id')->change();
            $table->foreign('city_id')->references('id')->on('city_tbl')->onDelete('no action');

             $table->unsignedBigInteger('institute_id')->nullable()->change();
            $table->foreign('institute_id')->references('id')->on('institute_tbl')->onDelete('no action');

             $table->unsignedBigInteger('study_plan_id')->nullable()->change();
            $table->foreign('study_plan_id')->references('id')->on('study_plan_tbl')->onDelete('no action');

             $table->unsignedBigInteger('heard_about_id')->change();
            $table->foreign('heard_about_id')->references('id')->on('heard_about_tbl')->onDelete('no action');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_profile_tbl', function (Blueprint $table) {
            //
             $table->dropForeign('study_group_id');
            $table->dropColumn('study_group_id');

            $table->dropForeign('user_id');
            $table->dropForeign('class_id');
            $table->dropForeign('curriculum_board_id');
            $table->dropForeign('institute_id');
            $table->dropForeign('study_plan_id');
            $table->dropForeign('heard_about_id');
        });
    }
};
