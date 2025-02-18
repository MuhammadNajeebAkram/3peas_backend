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
            $table -> date('dob')->after('gender_id');
            $table -> string('designation')->after('dob');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_profile_tbl', function (Blueprint $table) {
            //
            $table->dropColumn(['dob', 'designation']);
        });
    }
};
