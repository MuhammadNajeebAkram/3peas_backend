<?php

namespace App\Models\Freemium;

use App\Models\Book;
use App\Models\BookUnit;
use App\Models\BookUnitTopic;
use App\Models\CurriculumBoard;
use App\Models\ExamQuestion;
use App\Models\ModelPaper;
use App\Models\Subject;
use App\Models\TstPastPaper;
use App\Models\UserClass;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FreemiumAccessRule extends Model
{
    use HasFactory;

    protected $table = 'freemium_access_rules';

    protected $fillable = [
        'campaign_id',
        'target_scope',
        'target_status',
        'curriculum_board_id',
        'class_id',
        'subject_id',
        'book_id',
        'chapter_id',
        'topic_id',
        'question_id',
        'model_paper_id',
        'past_paper_id',
        'perm_mcqs',
        'perm_short_questions',
        'perm_long_questions',
        'perm_exercise_questions',
        'perm_supplementary_questions',
        'perm_answers',
        'perm_explanations',
        'perm_topic_contents',
        'perm_past_papers',
        'perm_model_papers',
        'rule_priority',
        'academic_target_snapshot',
    ];

    protected $casts = [
        'rule_priority'            => 'integer',
        'academic_target_snapshot' => 'array',
    ];

    // ==========================================
    // Relationships
    // ==========================================

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(FreemiumCampaign::class, 'campaign_id');
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

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class, 'book_id');
    }

    public function bookUnit(): BelongsTo
    {
        return $this->belongsTo(BookUnit::class, 'chapter_id');
    }

    public function bookUnitTopic(): BelongsTo
    {
        return $this->belongsTo(BookUnitTopic::class, 'topic_id');
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(ExamQuestion::class, 'question_id');
    }

    public function modelPaper(): BelongsTo
    {
        return $this->belongsTo(ModelPaper::class, 'model_paper_id');
    }

    public function pastPaper(): BelongsTo
    {
        return $this->belongsTo(TstPastPaper::class, 'past_paper_id');
    }

    // ==========================================
    // Query Scopes
    // ==========================================

    public function scopeActive($query)
    {
        return $query->where('target_status', 'active');
    }

    public function scopeOrphaned($query)
    {
        return $query->where('target_status', 'orphaned');
    }
}
