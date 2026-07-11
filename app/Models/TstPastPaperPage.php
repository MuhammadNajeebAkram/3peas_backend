<?php

namespace App\Models;

use App\Http\Services\AwsUploadService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TstPastPaperPage extends Model
{
    use HasFactory;

    protected $fillable = [
        'past_paper_id',
        'page_no',
        'paper_type',
        'image_path',
        'thumbnail_path',
        'image_width',
        'image_height',
    ];

    protected $casts = [
        'page_no' => 'integer',
        'image_width' => 'integer',
        'image_height' => 'integer',
    ];

    protected $appends = [
        'image_url',
        'thumbnail_url',
    ];

    public function pastPaper()
    {
        return $this->belongsTo(TstPastPaper::class, 'past_paper_id');
    }

    public function getImageUrlAttribute()
    {
        return $this->image_path ? app(AwsUploadService::class)->getS3Url($this->image_path) : null;
    }

    public function getThumbnailUrlAttribute()
    {
        return $this->thumbnail_path ? app(AwsUploadService::class)->getS3Url($this->thumbnail_path) : null;
    }
}
