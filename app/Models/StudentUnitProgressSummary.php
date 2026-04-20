<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;

class StudentUnitProgressSummary extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'offered_program_id',
        'unit_id',
        'total_questions',
        'practice_attempted',
        'practice_correct',
        'practice_wrong',
        'formal_attempted',
        'formal_correct',
        'formal_wrong',
        'distinct_questions_seen',
        'practice_accuracy',
        'formal_accuracy',
        'last_practiced_at',
        'last_tested_at',
    ];

    protected $casts = [
        'total_questions' => 'integer',
        'practice_attempted' => 'integer',
        'practice_correct' => 'integer',
        'practice_wrong' => 'integer',
        'formal_attempted' => 'integer',
        'formal_correct' => 'integer',
        'formal_wrong' => 'integer',
        'distinct_questions_seen' => 'integer',
        'practice_accuracy' => 'decimal:2',
        'formal_accuracy' => 'decimal:2',
        'last_practiced_at' => 'datetime',
        'last_tested_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(WebUser::class, 'user_id');
    }

    public function offeredProgram(): BelongsTo
    {
        return $this->belongsTo(OfferedProgram::class, 'offered_program_id');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(BookUnit::class, 'unit_id');
    }
}
