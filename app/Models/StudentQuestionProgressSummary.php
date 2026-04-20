<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;

class StudentQuestionProgressSummary extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'question_id',
        'offered_program_id',
        'subject_id',
        'unit_id',
        'practice_attempts',
        'practice_correct',
        'practice_wrong',
        'formal_attempts',
        'formal_correct',
        'formal_wrong',
        'last_practiced_at',
        'last_tested_at',
        'is_mastered',
    ];

    protected $casts = [
        'practice_attempts' => 'integer',
        'practice_correct' => 'integer',
        'practice_wrong' => 'integer',
        'formal_attempts' => 'integer',
        'formal_correct' => 'integer',
        'formal_wrong' => 'integer',
        'last_practiced_at' => 'datetime',
        'last_tested_at' => 'datetime',
        'is_mastered' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(WebUser::class, 'user_id');
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(ExamQuestion::class, 'question_id');
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
