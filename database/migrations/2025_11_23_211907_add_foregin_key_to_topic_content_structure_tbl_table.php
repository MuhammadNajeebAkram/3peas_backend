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
        Schema::table('topic_content_structure_tbl', function (Blueprint $table) {
            //
             $table->unsignedBigInteger('topic_id')->change();
             $table->unsignedBigInteger('topic_content_type_id')->change();

            $table->foreign('topic_id')->references('id')->on('book_unit_topic_tbl')->onDelete('cascade');
            $table->foreign('topic_content_type_id')->references('id')->on('topic_content_type_tbl')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('topic_content_structure_tbl', function (Blueprint $table) {
            //
             $table->dropForeign('topic_id');
              $table->dropForeign('topic_content_type_id');
        });
    }
};
