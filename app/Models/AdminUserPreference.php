<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AdminUserPreference extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'theme_mode',
        'primary_color',
        'sidebar_state',
        'sidebar_pinned',
        'sidebar_width',
        'topbar_density',
        'default_landing_page',
        'language',
        'text_direction',
        'timezone',
        'date_format',
        'time_format',
        'table_rows_per_page',
        'table_density',
        'sticky_table_header',
        'remember_filters',
        'remember_sorting',
        'editor_default_language',
        'editor_text_direction',
        'editor_font_family',
        'editor_toolbar_mode',
        'auto_save_enabled',
        'auto_save_interval_seconds',
        'dashboard_layout',
        'dashboard_refresh_interval',
        'dashboard_date_range',
        'dashboard_compact_mode',
        'notification_settings',
        'module_preferences',
    ];

    protected $casts = [
        'sidebar_pinned' => 'boolean',
        'sticky_table_header' => 'boolean',
        'remember_filters' => 'boolean',
        'remember_sorting' => 'boolean',
        'auto_save_enabled' => 'boolean',
        'dashboard_compact_mode' => 'boolean',
        'dashboard_layout' => 'array',
        'notification_settings' => 'array',
        'module_preferences' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
