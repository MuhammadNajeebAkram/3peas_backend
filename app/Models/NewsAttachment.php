<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class NewsAttachment extends Model
{
    use HasFactory;
    protected $table = 'news_attachments';
    protected $fillable = [
        'news_id',
        'path',  // file name      
        'file_type',
        'description',
        'activate',
        'size_kb',
    ];
    public function news()
    {
        return $this->belongsTo(News::class, 'news_id');
    }
}
