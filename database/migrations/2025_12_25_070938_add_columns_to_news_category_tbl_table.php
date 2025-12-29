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
        Schema::table('news_category_tbl', function (Blueprint $table) {
            //
            $table->string('image_path')->nullable();
            $table->string('slug')->unique()->after('category_name');
            $table->integer('priority_score')->index()->default(50);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('news_category_tbl', function (Blueprint $table) {
            //
            $table->dropColumn('image_path');
            $table->dropColumn('slug');
            $table->dropColumn('priority_score');
        });
    }
};
