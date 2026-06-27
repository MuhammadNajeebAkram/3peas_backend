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
        Schema::create('admin_user_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('theme_mode', 20)->default('light');
            $table->string('primary_color', 30)->nullable();
            $table->string('sidebar_state', 20)->default('expanded');
            $table->boolean('sidebar_pinned')->default(true);
            $table->unsignedSmallInteger('sidebar_width')->nullable();
            $table->string('topbar_density', 20)->default('standard');
            $table->string('default_landing_page')->nullable();
            $table->string('language', 20)->default('en');
            $table->string('text_direction', 10)->default('ltr');
            $table->string('timezone')->default('Asia/Karachi');
            $table->string('date_format', 30)->default('d-m-Y');
            $table->string('time_format', 10)->default('12h');
            $table->unsignedInteger('table_rows_per_page')->default(25);
            $table->string('table_density', 20)->default('standard');
            $table->boolean('sticky_table_header')->default(true);
            $table->boolean('remember_filters')->default(true);
            $table->boolean('remember_sorting')->default(true);
            $table->string('editor_default_language', 20)->default('en');
            $table->string('editor_text_direction', 10)->default('auto');
            $table->string('editor_font_family')->nullable();
            $table->string('editor_toolbar_mode', 20)->default('full');
            $table->boolean('auto_save_enabled')->default(false);
            $table->unsignedSmallInteger('auto_save_interval_seconds')->default(60);
            $table->json('dashboard_layout')->nullable();
            $table->unsignedSmallInteger('dashboard_refresh_interval')->default(0);
            $table->string('dashboard_date_range', 30)->default('this_month');
            $table->boolean('dashboard_compact_mode')->default(false);
            $table->json('notification_settings')->nullable();
            $table->json('module_preferences')->nullable();
            $table->timestamps();

            $table->index('theme_mode');
            $table->index('sidebar_state');
            $table->index('language');
            $table->index('timezone');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('admin_user_preferences');
    }
};
