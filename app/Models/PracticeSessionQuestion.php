<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;

class PracticeSessionQuestion extends Model
{
    use HasFactory;

    protected $fillable = [
        'session_id',
        'question_id',
        'selected_option_id',
        'question_order',
        'is_attempted',
        'is_correct',
        'answer_shown',
        'time_spent_seconds',
        'practiced_at',
    ];

    protected $casts = [
        'question_order' => 'integer',
        'is_attempted' => 'boolean',
        'is_correct' => 'boolean',
        'answer_shown' => 'boolean',
        'time_spent_seconds' => 'integer',
        'practiced_at' => 'datetime',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(PracticeSession::class, 'session_id');
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(ExamQuestion::class, 'question_id');
    }

    public function selectedOption(): BelongsTo
    {
        return $this->belongsTo(ExamQuestionAnswerOption::class, 'selected_option_id');
    }
}
