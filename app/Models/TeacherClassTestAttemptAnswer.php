<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeacherClassTestAttemptAnswer extends Model
{
    protected $table = 'teacher_class_test_attempt_answers';

    protected $fillable = [
        'selected_option_id',
        'selected_option_key',
        'answered_at',
    ];

    protected $casts = [
        'attempt_id' => 'integer',
        'teacher_class_test_question_id' => 'integer',
        'question_id' => 'integer',
        'scenario_group_id' => 'integer',
        'question_order' => 'integer',
        'selected_option_id' => 'integer',
        'correct_option_id_snapshot' => 'integer',
        'is_correct' => 'boolean',
        'marks_possible' => 'decimal:2',
        'negative_marks_possible' => 'decimal:2',
        'marks_awarded' => 'decimal:2',
        'answered_at' => 'datetime',
    ];

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(TeacherClassTestAttempt::class, 'attempt_id');
    }

    public function testQuestion(): BelongsTo
    {
        return $this->belongsTo(TeacherClassTestQuestion::class, 'teacher_class_test_question_id');
    }
}
