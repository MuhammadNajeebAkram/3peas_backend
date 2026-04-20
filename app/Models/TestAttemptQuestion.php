<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;

class TestAttemptQuestion extends Model
{
    use HasFactory;

    protected $fillable = [
        'attempt_id',
        'question_id',
        'question_order',
        'selected_option_id',
        'is_attempted',
        'is_correct',
        'marks',
        'obtained_marks',
        'time_spent_seconds',
    ];

    protected $casts = [
        'question_order' => 'integer',
        'is_attempted' => 'boolean',
        'is_correct' => 'boolean',
        'marks' => 'decimal:2',
        'obtained_marks' => 'decimal:2',
        'time_spent_seconds' => 'integer',
    ];

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(TestAttempt::class, 'attempt_id');
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
