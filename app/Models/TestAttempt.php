<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Model;

class TestAttempt extends Model
{
    use HasFactory;

    protected $fillable = [
        'test_id',
        'user_id',
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
        'attempted_questions' => 'integer',
        'correct_answers' => 'integer',
        'wrong_answers' => 'integer',
        'not_attempted_questions' => 'integer',
        'score' => 'decimal:2',
        'accuracy' => 'decimal:2',
        'started_at' => 'datetime',
        'submitted_at' => 'datetime',
    ];

    public function test(): BelongsTo
    {
        return $this->belongsTo(Test::class, 'test_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(WebUser::class, 'user_id');
    }

    public function questions(): HasMany
    {
        return $this->hasMany(TestAttemptQuestion::class, 'attempt_id');
    }
}
