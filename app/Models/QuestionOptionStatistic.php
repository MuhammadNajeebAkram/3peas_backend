<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;

class QuestionOptionStatistic extends Model
{
    use HasFactory;

    protected $fillable = [
        'question_id',
        'option_id',
        'selection_count',
        'practice_selection_count',
        'formal_selection_count',
        'answer_shown_selection_count',
    ];

    protected $casts = [
        'selection_count' => 'integer',
        'practice_selection_count' => 'integer',
        'formal_selection_count' => 'integer',
        'answer_shown_selection_count' => 'integer',
    ];

    public function question(): BelongsTo
    {
        return $this->belongsTo(ExamQuestion::class, 'question_id');
    }

    public function option(): BelongsTo
    {
        return $this->belongsTo(ExamQuestionAnswerOption::class, 'option_id');
    }
}
