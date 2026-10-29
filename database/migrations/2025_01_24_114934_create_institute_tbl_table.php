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
        if (!Schema::hasTable('city_tbl')) {
            Schema::create('city_tbl', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->boolean('activate')->default(true);
                $table->timestamps();
            });
        }

        Schema::create('institute_tbl', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('address');
            $table->foreignId('city_id')->constrained('city_tbl', 'id')->onDelete('no action');
            $table->string('phone');
            $table->string('sector');  //public, private
            $table->boolean('activate')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('institute_tbl');
    }
};
