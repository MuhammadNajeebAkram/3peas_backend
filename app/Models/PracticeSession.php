<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Model;

class PracticeSession extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'offered_program_id',
        'subject_id',
        'scope_type',
        'question_type',
        'time_limit_minutes',
        'total_questions',
        'attempted_questions',
        'correct_answers',
        'wrong_answers',
        'not_attempted_questions',
        'score',
        'accuracy',
        'started_at',
        'submitted_at',
        'status',
    ];

    protected $casts = [
        'time_limit_minutes' => 'integer',
        'total_questions' => 'integer',
        'attempted_questions' => 'integer',
        'correct_answers' => 'integer',
        'wrong_answers' => 'integer',
        'not_attempted_questions' => 'integer',
        'score' => 'decimal:2',
        'accuracy' => 'decimal:2',
        'started_at' => 'datetime',
        'submitted_at' => 'datetime',
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

    public function questions(): HasMany
    {
        return $this->hasMany(PracticeSessionQuestion::class, 'session_id');
    }

    public function scopes(): HasMany
    {
        return $this->hasMany(PracticeSessionScope::class, 'session_id');
    }
}
