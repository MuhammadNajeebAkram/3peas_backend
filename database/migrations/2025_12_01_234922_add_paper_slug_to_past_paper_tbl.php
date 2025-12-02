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
            $table->string('paper_slug')->after('paper_name')->nullable();
            
            // Add an index for fast lookups
            $table->index('paper_slug');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('past_paper_tbl', function (Blueprint $table) {
            //
            $table->dropIndex(['paper_slug']);
            $table->dropColumn('paper_slug');
        });
    }
};
