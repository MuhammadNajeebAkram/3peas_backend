<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TopicContentType extends Model
{
    use HasFactory;
    protected $table = 'topic_content_type_tbl';
    protected $fillable = [
        'name',
        'name_um',
        'has_child',
        'is_mcq',
        'activate',
    ];

    public function topicContentStructures()
    {
        return $this->hasMany('App\Models\TopicContentStructure', 'topic_content_type_id');
    }
   
}
