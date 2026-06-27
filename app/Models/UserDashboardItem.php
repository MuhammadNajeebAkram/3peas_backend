<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserDashboardItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'dashboard_item_id',
        'is_visible',
        'sort_order',
        'width',
        'settings',
    ];

    protected $casts = [
        'is_visible' => 'boolean',
        'settings' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function dashboardItem()
    {
        return $this->belongsTo(DashboardItem::class, 'dashboard_item_id');
    }
}
