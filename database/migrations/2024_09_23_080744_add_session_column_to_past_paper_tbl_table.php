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
        Schema::table('past_paper_tbl', function (Blueprint $table) {
            //
            $table->integer('session_id')->default(1)->after('year');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('past_paper_tbl', function (Blueprint $table) {
            //
            $table->dropColumn('session_id');
        });
    }
};
