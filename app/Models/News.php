<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class News extends Model
{
    use HasFactory;

    

    //protected $table = 'news_tbl';
    protected $fillable = [
        'title',
        'content',
        'category_id',
        'language',                
        'published_at',
        'slug',
        'summary',
        'meta_keywords',
        'meta_description',
        'news_type',
        'featured_image',       
        'thumbnail_image',
        'video_url',
        'og_image',       
        'is_breaking',
        'is_featured',
        'is_published',
        'expires_at',
        'status',
        'meta_title',       
       'institute_id',
        'event_date',
        'location',
        'video_url',
        'display_order',
        'created_by',
        'updated_by',
        'is_activated',

    ];

    protected $casts = [
        'published_at' => 'datetime',
        'expires_at' => 'date',
        'event_date' => 'date',
    ];

    public function category()
    {
        return $this->belongsTo(NewsCategory::class, 'category_id');
    }   

    public function media()
    {
        return $this->hasMany(NewsMedia::class, 'news_id');
    }
    public function tickers()
    {
        return $this->hasMany(NewsTicker::class, 'news_id');
    }
    public function institute()
    {
        return $this->belongsTo(Institute::class, 'institute_id');
    }
}
