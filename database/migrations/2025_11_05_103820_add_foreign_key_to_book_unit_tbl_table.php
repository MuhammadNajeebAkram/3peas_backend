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
        Schema::table('book_unit_tbl', function (Blueprint $table) {
            //
            $table->unsignedBigInteger('book_id')->change();

            $table->foreign('book_id')->references('id')->on('book_tbl')->onDelete('cascade');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('book_unit_tbl', function (Blueprint $table) {
            //
            $table->dropForeign('book_id');
        });
    }
};
