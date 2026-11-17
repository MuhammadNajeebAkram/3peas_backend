<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SloTopic extends Model
{
    use HasFactory;

    protected $table = 'slo_topic_tbl';

    protected $fillable = [
        'slo_domain_id',
        'class_id',
        'slo_code',
        'slo_title',
        'slo_title_um',
        'benchmark_text',
        'description',
        'assessment_type',
        'cognitive_level',
        'is_practical',
        'is_optional',
        'is_alp',
        'remarks',
        'sort_order',
        'activate',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'is_practical' => 'boolean',
        'is_optional' => 'boolean',
        'is_alp' => 'boolean',
        'activate' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function sloDomain()
    {
        return $this->belongsTo(SloDomain::class, 'slo_domain_id');
    }

    public function class()
    {
        return $this->belongsTo(UserClass::class, 'class_id');
    }

    public function units()
    {
        return $this->belongsToMany(BookUnit::class, 'unit_slo_topic_tbl', 'slo_topic_id', 'unit_id')
            ->withTimestamps();
    }

    public function topics()
    {
        return $this->belongsToMany(BookUnitTopic::class, 'topic_slo_topic_tbl', 'slo_topic_id', 'topic_id')
            ->withTimestamps();
    }

    public function questions()
    {
        return $this->hasMany(ExamQuestion::class, 'slo_topic_id');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
