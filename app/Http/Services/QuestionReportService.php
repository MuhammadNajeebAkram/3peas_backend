<?php

namespace App\Http\Services;

use App\Models\ExamQuestion;
use App\Models\QuestionReport;
use App\Models\QuestionReportAuditLog;
use App\Models\User;
use App\Models\WebUser;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class QuestionReportService
{
    /**
     * Submit a report for a question.
     * Enforces duplicate submission protection (short window per user/question/category)
     * while preserving multiple legitimate reports from different users or distinct issues.
     */
    public function submitReport(WebUser $user, array $data): QuestionReport
    {
        $questionId = (int) $data['question_id'];
        $category = $data['report_category'];
        $description = isset($data['description']) ? trim((string) $data['description']) : null;
        $portal = $data['portal'] ?? ($user->role === 'teacher' ? QuestionReport::PORTAL_TEACHER : QuestionReport::PORTAL_STUDENT);
        $pageContext = $data['page_context'] ?? null;
        $metadata = $data['metadata'] ?? null;

        // Verify question existence
        $question = ExamQuestion::with(['topic.unit.book'])->find($questionId);
        if (!$question) {
            throw ValidationException::withMessages([
                'question_id' => ['The specified question was not found.'],
            ]);
        }

        // Duplicate protection: prevent accidental rapid re-submissions within 10 seconds by same user for same question & category
        $recentDuplicate = QuestionReport::where('question_id', $questionId)
            ->where('reporter_web_user_id', $user->id)
            ->where('report_category', $category)
            ->where('created_at', '>=', now()->subSeconds(10))
            ->first();

        if ($recentDuplicate) {
            return $recentDuplicate;
        }

        // Resolve authoritative curriculum metadata from canonical relationships
        $topic = $question->topic;
        $unit = $topic?->unit;
        $book = $unit?->book;

        $curriculumBoardId = $book?->curriculum_board_id;
        $classId = $book?->class_id;
        $subjectId = $book?->subject_id;
        $bookUnitId = $unit?->id;
        $bookUnitTopicId = $topic?->id;

        return DB::transaction(function () use (
            $questionId,
            $user,
            $category,
            $description,
            $portal,
            $pageContext,
            $metadata,
            $curriculumBoardId,
            $classId,
            $subjectId,
            $bookUnitId,
            $bookUnitTopicId
        ) {
            return QuestionReport::create([
                'question_id' => $questionId,
                'reporter_web_user_id' => $user->id,
                'reporter_role' => $user->role ?? 'student',
                'report_category' => $category,
                'description' => $description,
                'status' => QuestionReport::STATUS_PENDING,
                'curriculum_board_id' => $curriculumBoardId,
                'class_id' => $classId,
                'subject_id' => $subjectId,
                'book_unit_id' => $bookUnitId,
                'book_unit_topic_id' => $bookUnitTopicId,
                'portal' => $portal,
                'page_context' => $pageContext,
                'metadata' => $metadata,
            ]);
        });
    }

    /**
     * Retrieve paginated reports submitted by the authenticated user (students/teachers).
     * Internal notes and admin IDs are never exposed to students or teachers.
     */
    public function getUserReports(WebUser $user, int $perPage = 15): LengthAwarePaginator
    {
        return QuestionReport::with([
            'question:id,question,question_um,question_type',
            'subject:id,subject_name',
            'userClass:id,class_name',
        ])
            ->where('reporter_web_user_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);
    }

    /**
     * Admin dashboard summary counts.
     */
    public function getAdminSummary(): array
    {
        $base = QuestionReport::query();

        return [
            'total' => (clone $base)->count(),
            'pending' => (clone $base)->where('status', QuestionReport::STATUS_PENDING)->count(),
            'under_review' => (clone $base)->where('status', QuestionReport::STATUS_UNDER_REVIEW)->count(),
            'resolved' => (clone $base)->where('status', QuestionReport::STATUS_RESOLVED)->count(),
            'rejected' => (clone $base)->where('status', QuestionReport::STATUS_REJECTED)->count(),
            'distinct_questions_reported' => (clone $base)->distinct('question_id')->count('question_id'),
        ];
    }

    /**
     * Admin paginated reports with flexible filters.
     */
    public function getAdminReports(array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        $query = QuestionReport::with([
            'question:id,question,question_um,question_type',
            'reporter:id,name,email,role',
            'curriculumBoard:id,name',
            'userClass:id,class_name',
            'subject:id,subject_name',
            'bookUnit:id,unit_name,unit_no',
            'bookUnitTopic:id,topic_name,topic_no',
        ])->withCount([
            'question as total_question_reports' => function (Builder $b) {
                // Count all reports for this same question
            },
        ]);

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (!empty($filters['category'])) {
            $query->where('report_category', $filters['category']);
        }
        if (!empty($filters['reporter_role'])) {
            $query->where('reporter_role', $filters['reporter_role']);
        }
        if (!empty($filters['curriculum_board_id'])) {
            $query->where('curriculum_board_id', (int) $filters['curriculum_board_id']);
        }
        if (!empty($filters['class_id'])) {
            $query->where('class_id', (int) $filters['class_id']);
        }
        if (!empty($filters['subject_id'])) {
            $query->where('subject_id', (int) $filters['subject_id']);
        }
        if (!empty($filters['question_id'])) {
            $query->where('question_id', (int) $filters['question_id']);
        }
        if (!empty($filters['date_from'])) {
            $query->whereDate('created_at', '>=', $filters['date_from']);
        }
        if (!empty($filters['date_to'])) {
            $query->whereDate('created_at', '<=', $filters['date_to']);
        }
        if (!empty($filters['search'])) {
            $term = '%' . trim($filters['search']) . '%';
            $query->where(function (Builder $b) use ($term) {
                $b->where('description', 'like', $term)
                    ->orWhereHas('question', function (Builder $qb) use ($term) {
                        $qb->where('question', 'like', $term)
                            ->orWhere('question_um', 'like', $term);
                    })
                    ->orWhereHas('reporter', function (Builder $rb) use ($term) {
                        $rb->where('name', 'like', $term)
                            ->orWhere('email', 'like', $term);
                    });
            });
        }

        return $query->orderBy('created_at', 'desc')->paginate($perPage);
    }

    /**
     * Retrieve a detailed report including other reports on the same question and complete audit history.
     */
    public function getAdminReportDetail(int $reportId): QuestionReport
    {
        $report = QuestionReport::with([
            'question.topic.unit.book',
            'question.answerOptions',
            'question.answers',
            'reporter:id,name,email,role',
            'curriculumBoard',
            'userClass',
            'subject',
            'bookUnit',
            'bookUnitTopic',
            'auditLogs.admin:id,name,email',
        ])->findOrFail($reportId);

        // Fetch other reports for this same question
        $otherReports = QuestionReport::with(['reporter:id,name,email,role'])
            ->where('question_id', $report->question_id)
            ->where('id', '!=', $report->id)
            ->orderBy('created_at', 'desc')
            ->get();

        $report->setRelation('otherReports', $otherReports);

        return $report;
    }

    /**
     * Update report status and log administrative audit trail.
     * Note: Resolving a report does NOT automatically edit or alter the question.
     */
    public function updateReportStatus(QuestionReport $report, User $adminUser, string $newStatus, ?string $internalNote = null): QuestionReport
    {
        $previousStatus = $report->status;

        DB::transaction(function () use ($report, $adminUser, $previousStatus, $newStatus, $internalNote) {
            $report->status = $newStatus;
            if (in_array($newStatus, [QuestionReport::STATUS_RESOLVED, QuestionReport::STATUS_REJECTED], true)) {
                $report->resolved_at = now();
            } else {
                $report->resolved_at = null;
            }
            $report->save();

            QuestionReportAuditLog::create([
                'question_report_id' => $report->id,
                'admin_user_id' => $adminUser->id,
                'previous_status' => $previousStatus,
                'new_status' => $newStatus,
                'internal_note' => $internalNote ? trim($internalNote) : null,
                'action_type' => 'status_change',
                'created_at' => now(),
            ]);
        });

        return $report->fresh(['auditLogs.admin']);
    }

    /**
     * Add an internal administrative note to a report without modifying its status.
     */
    public function addInternalNote(QuestionReport $report, User $adminUser, string $note): QuestionReportAuditLog
    {
        return QuestionReportAuditLog::create([
            'question_report_id' => $report->id,
            'admin_user_id' => $adminUser->id,
            'previous_status' => $report->status,
            'new_status' => $report->status,
            'internal_note' => trim($note),
            'action_type' => 'note_added',
            'created_at' => now(),
        ]);
    }

    /**
     * Bulk update status for all pending reports belonging to a specific question.
     */
    public function bulkUpdateByQuestion(int $questionId, User $adminUser, string $newStatus, ?string $internalNote = null): int
    {
        $reports = QuestionReport::where('question_id', $questionId)
            ->where('status', '!=', $newStatus)
            ->get();

        DB::transaction(function () use ($reports, $adminUser, $newStatus, $internalNote) {
            foreach ($reports as $report) {
                $prev = $report->status;
                $report->status = $newStatus;
                $report->resolved_at = in_array($newStatus, [QuestionReport::STATUS_RESOLVED, QuestionReport::STATUS_REJECTED], true) ? now() : null;
                $report->save();

                QuestionReportAuditLog::create([
                    'question_report_id' => $report->id,
                    'admin_user_id' => $adminUser->id,
                    'previous_status' => $prev,
                    'new_status' => $newStatus,
                    'internal_note' => $internalNote ? trim($internalNote) : 'Bulk update applied across question reports.',
                    'action_type' => 'bulk_status_change',
                    'created_at' => now(),
                ]);
            }
        });

        return $reports->count();
    }
}
