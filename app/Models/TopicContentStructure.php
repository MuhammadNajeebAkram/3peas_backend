<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TopicContentStructure extends Model
{
    use HasFactory;
    protected $table = 'topic_content_structure_tbl';
    protected $fillable = [
        'topic_id',
        'topic_content_type_id',       
    ];

    public function topic()
    {
        return $this->belongsTo('App\Models\BookUnitTopic', 'topic_id');
    }
    public function topicContentType()
    {
        return $this->belongsTo('App\Models\TopicContentType', 'topic_content_type_id');
    }
}
