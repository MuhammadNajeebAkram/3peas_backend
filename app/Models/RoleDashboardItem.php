<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RoleDashboardItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'role_id',
        'dashboard_item_id',
        'is_visible',
        'sort_order',
        'settings',
    ];

    protected $casts = [
        'is_visible' => 'boolean',
        'settings' => 'array',
    ];

    public function role()
    {
        return $this->belongsTo(Role::class, 'role_id');
    }

    public function dashboardItem()
    {
        return $this->belongsTo(DashboardItem::class, 'dashboard_item_id');
    }
}
