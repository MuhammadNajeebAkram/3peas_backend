<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class QuestionReport extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_UNDER_REVIEW = 'under_review';
    public const STATUS_RESOLVED = 'resolved';
    public const STATUS_REJECTED = 'rejected';

    public const CATEGORIES = [
        'visibility_problem',
        'rendering_issue',
        'incorrect_question',
        'incorrect_answer',
        'incorrect_explanation',
        'missing_explanation',
        'typographical_error',
        'missing_content',
        'duplicate_question',
        'wrong_syllabus_chapter',
        'inappropriate_content',
        'other',
    ];

    public const PORTAL_STUDENT = 'student';
    public const PORTAL_TEACHER = 'teacher';

    protected $table = 'question_reports';

    protected $fillable = [
        'question_id',
        'reporter_web_user_id',
        'reporter_role',
        'report_category',
        'description',
        'status',
        'curriculum_board_id',
        'class_id',
        'subject_id',
        'book_unit_id',
        'book_unit_topic_id',
        'portal',
        'page_context',
        'metadata',
        'resolved_at',
    ];

    protected $casts = [
        'question_id' => 'integer',
        'reporter_web_user_id' => 'integer',
        'curriculum_board_id' => 'integer',
        'class_id' => 'integer',
        'subject_id' => 'integer',
        'book_unit_id' => 'integer',
        'book_unit_topic_id' => 'integer',
        'metadata' => 'array',
        'resolved_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function question(): BelongsTo
    {
        return $this->belongsTo(ExamQuestion::class, 'question_id');
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(WebUser::class, 'reporter_web_user_id');
    }

    public function curriculumBoard(): BelongsTo
    {
        return $this->belongsTo(CurriculumBoard::class, 'curriculum_board_id');
    }

    public function userClass(): BelongsTo
    {
        return $this->belongsTo(UserClass::class, 'class_id');
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class, 'subject_id');
    }

    public function bookUnit(): BelongsTo
    {
        return $this->belongsTo(BookUnit::class, 'book_unit_id');
    }

    public function bookUnitTopic(): BelongsTo
    {
        return $this->belongsTo(BookUnitTopic::class, 'book_unit_topic_id');
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(QuestionReportAuditLog::class, 'question_report_id')->orderBy('created_at', 'desc');
    }
}
