<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class NewsMedia extends Model
{
    use HasFactory;

    protected $fillable = [
        'news_id',
        'media_type',
        'media_url',
        'thumbnail_url',
        'caption',
        'alt_text',
        'display_order',
        'is_active',
        'created_by',
        'updated_by',
    ];

    public function news()
    {
        return $this->belongsTo(News::class, 'news_id');
    }
}
