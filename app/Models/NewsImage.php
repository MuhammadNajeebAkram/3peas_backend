<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class NewsImage extends Model
{
    use HasFactory;
    protected $table = 'news_images';
    protected $fillable = [
        'news_id',
        'image_path',
        'alt_text',
        'height',
        'width',
        'size_kb',
    ];
    public function news()
    {
        return $this->belongsTo(News::class, 'news_id');
    }
    
}
