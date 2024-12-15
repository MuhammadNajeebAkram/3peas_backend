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
        Schema::table('blog_tbl', function (Blueprint $table) {
            //
            $table->integer('language')->default(0)->after('content');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('blog_tbl', function (Blueprint $table) {
            //
            $table->dropColumn('language');
        });
    }
};
