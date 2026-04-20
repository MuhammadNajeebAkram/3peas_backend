<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;

class StudentActivity extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'activity_type',
        'title',
        'description',
        'offered_program_id',
        'subject_id',
        'unit_id',
        'reference_id',
        'reference_type',
        'meta',
        'activity_at',
    ];

    protected $casts = [
        'meta' => 'array',
        'activity_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(WebUser::class, 'user_id');
    }

    public function offeredProgram(): BelongsTo
    {
        return $this->belongsTo(OfferedProgram::class, 'offered_program_id');
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class, 'subject_id');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(BookUnit::class, 'unit_id');
    }
}
