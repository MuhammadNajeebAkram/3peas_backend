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
        Schema::table('news_tbl', function (Blueprint $table) {
            //
            $table->renameColumn('haveFile', 'has_attachment');
            $table->date('published_at')->nullable()->index();
            
           
           
            $table->index('language');
            $table->index('activate');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('news_tbl', function (Blueprint $table) {
            //
            $table->renameColumn('has_attachment', 'haveFile');
            $table->dropIndex(['published_at']);
            $table->dropIndex(['language']);
            $table->dropIndex(['activate']);
        });
    }
};
