<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DashboardItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'title',
        'category',
        'widget_type',
        'data_key',
        'permission_name',
        'width',
        'sort_order',
        'is_active',
        'description',
        'settings',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'settings' => 'array',
    ];

    public function roles()
    {
        return $this->belongsToMany(Role::class, 'role_dashboard_items', 'dashboard_item_id', 'role_id')
            ->withPivot(['is_visible', 'sort_order', 'settings'])
            ->withTimestamps();
    }

    public function userDashboardItems()
    {
        return $this->hasMany(UserDashboardItem::class, 'dashboard_item_id');
    }
}
