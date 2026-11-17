<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeacherClassTestQuestion extends Model
{
    public const SOURCE_BANK = 'bank';

    protected $table = 'teacher_class_test_questions';

    protected $fillable = [
        'question_id',
        'unit_id',
        'topic_id',
        'slo_topic_id',
        'scenario_group_id',
        'question_order',
        'marks',
        'negative_marks',
        'question_source',
        'question_text_snapshot',
        'question_text_um_snapshot',
        'scenario_text_snapshot',
        'scenario_text_um_snapshot',
        'scenario_image_snapshot',
        'options_snapshot_json',
        'correct_option_id_snapshot',
        'correct_option_key_snapshot',
        'explanation_snapshot',
        'explanation_um_snapshot',
        'metadata_snapshot_json',
    ];

    protected $casts = [
        'teacher_class_test_id' => 'integer',
        'question_id' => 'integer',
        'unit_id' => 'integer',
        'topic_id' => 'integer',
        'slo_topic_id' => 'integer',
        'scenario_group_id' => 'integer',
        'question_order' => 'integer',
        'marks' => 'decimal:2',
        'negative_marks' => 'decimal:2',
        'options_snapshot_json' => 'array',
        'correct_option_id_snapshot' => 'integer',
        'metadata_snapshot_json' => 'array',
    ];

    public function test(): BelongsTo
    {
        return $this->belongsTo(TeacherClassTest::class, 'teacher_class_test_id');
    }
}
