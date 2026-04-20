<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;

class QuestionStatistic extends Model
{
    use HasFactory;

    protected $fillable = [
        'question_id',
        'attempt_count',
        'correct_count',
        'wrong_count',
        'skip_count',
        'difficulty_index',
        'discrimination_index',
        'computed_difficulty_band',
        'is_calibrated',
        'last_calculated_at',
    ];

    protected $casts = [
        'attempt_count' => 'integer',
        'correct_count' => 'integer',
        'wrong_count' => 'integer',
        'skip_count' => 'integer',
        'difficulty_index' => 'decimal:2',
        'discrimination_index' => 'decimal:2',
        'is_calibrated' => 'boolean',
        'last_calculated_at' => 'datetime',
    ];

    public function question(): BelongsTo
    {
        return $this->belongsTo(ExamQuestion::class, 'question_id');
    }
}
