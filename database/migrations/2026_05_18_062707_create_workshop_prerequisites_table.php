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
        Schema::create('workshop_prerequisites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workshop_id')
                ->constrained('workshops')
                ->cascadeOnDelete();

            $table->string('title');
            $table->text('description')->nullable();
            $table->boolean('is_required')->default(false);
            $table->unsignedInteger('display_order')->default(0);

            $table->timestamps();

            $table->index(['workshop_id', 'display_order'], 'wrk_pre_req_order_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('workshop_prerequisites');
    }
};
