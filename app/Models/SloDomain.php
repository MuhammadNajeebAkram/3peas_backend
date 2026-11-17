<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SloDomain extends Model
{
    use HasFactory;

    protected $table = 'slo_domain_tbl';

    protected $fillable = [
        'curriculum_board_id',
        'subject_id',
        'domain_code',
        'domain_name',
        'domain_name_um',
        'standard_statement',
        'total_periods',
        'sort_order',
        'activate',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'activate' => 'boolean',
        'total_periods' => 'integer',
        'sort_order' => 'integer',
    ];

    public function curriculumBoard()
    {
        return $this->belongsTo(CurriculumBoard::class, 'curriculum_board_id');
    }

    public function subject()
    {
        return $this->belongsTo(Subject::class, 'subject_id');
    }

    public function sloTopics()
    {
        return $this->hasMany(SloTopic::class, 'slo_domain_id')
            ->orderBy('sort_order')
            ->orderBy('slo_code');
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
