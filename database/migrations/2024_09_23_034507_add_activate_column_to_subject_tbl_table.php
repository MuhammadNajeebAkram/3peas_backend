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
            $table->boolean('activate')->default(true)->after('icon_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('subject_tbl', function (Blueprint $table) {
            //
            $table->dropColumn('activate');
        });
    }
};