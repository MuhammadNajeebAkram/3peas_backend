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
        Schema::table('subject_tbl', function (Blueprint $table) {
            //
            $table->string('subject_name_um')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('subject_tbl', function (Blueprint $table) {
            //
             $table->dropColumn('subject_name_um');
        });
    }
};
