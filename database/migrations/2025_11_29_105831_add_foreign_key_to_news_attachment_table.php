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
        Schema::table('news_attachments', function (Blueprint $table) {
            //
            $table->unsignedBigInteger('news_id')->change();
            $table->foreign('news_id')->references('id')->on('news_tbl')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('news_attachments', function (Blueprint $table) {
            //
        });
    }
};
