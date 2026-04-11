<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class NewsTicker extends Model
{
    use HasFactory;

    protected $fillable = [
        'news_id',
        'ticker_text',
        'ticker_link',
        'is_active',
        'display_order',
        'start_time',
        'end_time',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'start_time' => 'datetime',
        'end_time' => 'datetime',
    ];

    public function news()
    {
        return $this->belongsTo(News::class, 'news_id');
    }
}
