<?php

namespace App\Http\Services;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * QuestionAuthoringSecurityService
 *
 * Dedicated, decoupled authorization service for question authoring and mutation operations.
 * Operates strictly on baseline production tables (users, roles, permissions, role_permission_scopes,
 * exam_question_tbl, question_scenario_groups_tbl, book_unit_topic_tbl, book_unit_tbl, book_tbl).
 *
 * Has ZERO dependencies on:
 * - ai_providers / ai_models (Migration 1: 2026_11_07_000001)
 * - slo_topic_tbl / slo_domain_tbl (Migrations 2-6: 2026_11_07_000002-000006)
 * - question_reports (Migrations 20-21: 2026_11_13_000001-000002)
 * - teacher_classes (Migrations 7-19: 2026_11_10_000001-2026_11_12_000001)
 */
class QuestionAuthoringSecurityService
{
    /**
     * Resolve the authenticated administrative user strictly.
     * WebUser (students, teachers, public users) are never accepted as administrators.
     */
    public function resolveAdminUser(Request $request): ?User
    {
        $user = $request->user('api') ?: auth('api')->user() ?: $request->user() ?: auth()->user();

        if ($user instanceof User) {
            return $user;
        }

        return null;
    }

    /**
     * Check if the request is authorized to perform an action on the given question scope.
     *
     * Rules:
     * 1. Unauthenticated or non-admin users (WebUser) are strictly rejected (false).
     * 2. Users without a role_id are strictly rejected (false).
     * 3. super_admin role bypasses all scope and permission checks (true).
     * 4. Users lacking the required permission are strictly rejected (false).
     * 5. Unscoped admin users (no rows in role_permission_scopes for permission) have full access (true).
     * 6. Scoped admin users must match at least one scope against the provided context.
     * 7. Scoped admin users with null context fail closed (false).
     */
    public function canAccessQuestionScope(Request $request, string $permissionName, ?array $context = null): bool
    {
        $admin = $this->resolveAdminUser($request);

        if (!$admin || empty($admin->role_id)) {
            return false;
        }

        $admin->loadMissing('role.permissions');

        $roleName = str_replace(['-', ' '], '_', strtolower(trim((string) $admin->role?->name)));
        if ($roleName === 'super_admin') {
            return true;
        }

        $hasPermission = $admin->role?->permissions?->contains('name', $permissionName) ?? false;
        if (!$hasPermission) {
            return false;
        }

        $scopes = DB::table('role_permission_scopes as scopes')
            ->join('permissions', 'permissions.id', '=', 'scopes.permission_id')
            ->where('scopes.role_id', $admin->role_id)
            ->where('permissions.name', $permissionName)
            ->get(['scopes.scope_type', 'scopes.scope_id']);

        if ($scopes->isEmpty()) {
            return true;
        }

        if (!$context) {
            return false;
        }

        foreach ($scopes as $scope) {
            $contextKey = $this->scopeContextKey($scope->scope_type);

            if ($contextKey && isset($context[$contextKey]) && (int) $context[$contextKey] === (int) $scope->scope_id) {
                return true;
            }
        }

        return false;
    }

    /**
     * Retrieve the raw scope collection for an admin user and permission.
     * Returns null if super_admin (bypasses all scoping).
     */
    public function questionPermissionScopes(Request $request, string $permissionName)
    {
        $admin = $this->resolveAdminUser($request);

        if (!$admin || empty($admin->role_id)) {
            return collect();
        }

        $admin->loadMissing('role');

        $roleName = str_replace(['-', ' '], '_', strtolower(trim((string) $admin->role?->name)));
        if ($roleName === 'super_admin') {
            return null;
        }

        return DB::table('role_permission_scopes as scopes')
            ->join('permissions', 'permissions.id', '=', 'scopes.permission_id')
            ->where('scopes.role_id', $admin->role_id)
            ->where('permissions.name', $permissionName)
            ->get(['scopes.scope_type', 'scopes.scope_id']);
    }

    /**
     * Helper to return an immediate 403 Forbidden JsonResponse if authorization fails,
     * or null if authorization succeeds.
     */
    public function authorize(Request $request, string $permissionName, ?array $context = null, string $message = 'Unauthorized. Administrator access required.'): ?JsonResponse
    {
        if (!$this->canAccessQuestionScope($request, $permissionName, $context)) {
            return response()->json([
                'success' => false,
                'message' => $message,
            ], 403);
        }

        return null;
    }

    /**
     * Map a scope_type string to its context key.
     */
    public function scopeContextKey(string $scopeType): ?string
    {
        return match ($scopeType) {
            'curriculum_board' => 'curriculum_board_id',
            'class' => 'class_id',
            'subject' => 'subject_id',
            'book' => 'book_id',
            'unit' => 'unit_id',
            'topic' => 'topic_id',
            'question_type' => 'question_type',
            'cognitive_domain' => 'cognitive_domain',
            'topic_content' => 'topic_content',
            default => null,
        };
    }

    /**
     * Resolve question context array from database for a given question ID.
     */
    public function questionContextById(int|string $questionId): ?array
    {
        $question = DB::table('exam_question_tbl as q')
            ->leftJoin('book_unit_topic_tbl as topics', 'topics.id', '=', 'q.topic_id')
            ->leftJoin('book_unit_tbl as units', function ($join) {
                $join->on('units.id', '=', DB::raw('COALESCE(q.unit_id, topics.unit_id)'));
            })
            ->leftJoin('book_tbl as books', function ($join) {
                $join->on('books.id', '=', DB::raw('COALESCE(q.book_id, units.book_id)'));
            })
            ->where('q.id', $questionId)
            ->select(
                'q.topic_id',
                DB::raw('COALESCE(q.unit_id, topics.unit_id) as unit_id'),
                DB::raw('COALESCE(q.book_id, units.book_id) as book_id'),
                'books.subject_id',
                'books.class_id',
                'books.curriculum_board_id',
                'q.question_type',
                'q.cognitive_domain',
                'q.topic_content'
            )
            ->first();

        if (!$question) {
            return null;
        }

        return [
            'topic_id' => $question->topic_id ? (int) $question->topic_id : null,
            'unit_id' => $question->unit_id ? (int) $question->unit_id : null,
            'book_id' => $question->book_id ? (int) $question->book_id : null,
            'subject_id' => $question->subject_id ? (int) $question->subject_id : null,
            'class_id' => $question->class_id ? (int) $question->class_id : null,
            'curriculum_board_id' => $question->curriculum_board_id ? (int) $question->curriculum_board_id : null,
            'question_type' => $question->question_type ? (int) $question->question_type : null,
            'cognitive_domain' => $question->cognitive_domain ? (int) $question->cognitive_domain : null,
            'topic_content' => $question->topic_content ? (int) $question->topic_content : null,
        ];
    }

    /**
     * Resolve question context array from database for a given scenario group ID.
     */
    public function scenarioGroupContextById(int|string $scenarioGroupId): ?array
    {
        $group = DB::table('question_scenario_groups_tbl')->where('id', $scenarioGroupId)->first();
        if (!$group) {
            return null;
        }

        return [
            'topic_id' => $group->topic_id ? (int) $group->topic_id : null,
            'unit_id' => $group->unit_id ? (int) $group->unit_id : null,
            'book_id' => $group->book_id ? (int) $group->book_id : null,
        ];
    }

    /**
     * Resolve question context from incoming request parameters.
     */
    public function questionContextFromRequest(Request $request): ?array
    {
        $context = [
            'topic_id' => $request->filled('topic_id') ? (int) $request->topic_id : null,
            'unit_id' => $request->filled('unit_id') ? (int) $request->unit_id : null,
            'book_id' => $request->filled('book_id') ? (int) $request->book_id : null,
            'question_type' => $request->filled('question_type') ? (int) $request->question_type : null,
            'cognitive_domain' => $request->filled('cognitive_domain') ? (int) $request->cognitive_domain : null,
            'topic_content' => $request->filled('topic_content') ? (int) $request->topic_content : null,
            'subject_id' => null,
            'class_id' => null,
            'curriculum_board_id' => null,
        ];

        if ($context['topic_id']) {
            $topicContext = DB::table('book_unit_topic_tbl as topics')
                ->leftJoin('book_unit_tbl as units', 'units.id', '=', 'topics.unit_id')
                ->leftJoin('book_tbl as books', 'books.id', '=', 'units.book_id')
                ->where('topics.id', $context['topic_id'])
                ->select(
                    'topics.id as topic_id',
                    'units.id as unit_id',
                    'books.id as book_id',
                    'books.subject_id',
                    'books.class_id',
                    'books.curriculum_board_id'
                )
                ->first();

            if ($topicContext) {
                $context['unit_id'] = $context['unit_id'] ?: (int) $topicContext->unit_id;
                $context['book_id'] = $context['book_id'] ?: (int) $topicContext->book_id;
                $context['subject_id'] = $topicContext->subject_id ? (int) $topicContext->subject_id : null;
                $context['class_id'] = $topicContext->class_id ? (int) $topicContext->class_id : null;
                $context['curriculum_board_id'] = $topicContext->curriculum_board_id ? (int) $topicContext->curriculum_board_id : null;
            }
        }

        if ($context['unit_id'] && !$context['book_id']) {
            $unitContext = DB::table('book_unit_tbl')
                ->where('id', $context['unit_id'])
                ->select('book_id')
                ->first();

            $context['book_id'] = $unitContext?->book_id ? (int) $unitContext->book_id : null;
        }

        if ($context['book_id']) {
            $bookContext = DB::table('book_tbl')
                ->where('id', $context['book_id'])
                ->select('subject_id', 'class_id', 'curriculum_board_id')
                ->first();

            if ($bookContext) {
                $context['subject_id'] = $bookContext->subject_id ? (int) $bookContext->subject_id : null;
                $context['class_id'] = $bookContext->class_id ? (int) $bookContext->class_id : null;
                $context['curriculum_board_id'] = $bookContext->curriculum_board_id ? (int) $bookContext->curriculum_board_id : null;
            }
        }

        return $context;
    }
}
