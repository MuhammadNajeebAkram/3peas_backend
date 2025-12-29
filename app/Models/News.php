<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class News extends Model
{
    use HasFactory;

    protected $table = 'news_tbl';
    protected $fillable = [
        'title',
        'content',
        'category_id',
        'language',
        'activate',
        'has_attachment',
        'published_at',
        'slug',
        'description',
        'meta_description',
        'featured_image',
        'breaking_news_image',
        'thumbnail_image',
        'og_image',
        'priority_score',
        'is_breaking_news',
        'expires_at',
        'status',
        'meta_title',
        'url_link',
        'ticker_text'

    ];

    public function category()
    {
        return $this->belongsTo(NewsCategory::class, 'category_id');
    }
    public function attachments()
    {
        return $this->hasMany(NewsAttachment::class, 'news_id');
    }

    public function contentImages()
    {
        return $this->hasMany(NewsImage::class, 'news_id');
    }
}
