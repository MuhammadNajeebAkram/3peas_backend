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
        Schema::table('book_unit_topic_tbl', function (Blueprint $table) {
            //
            $table->string('slo_no')->nullable()->after('topic_no');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('book_unit_topic_tbl', function (Blueprint $table) {
            //
            $table->dropColumn('slo_no');
        });
    }
};
