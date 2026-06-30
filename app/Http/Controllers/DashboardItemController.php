<?php

namespace App\Http\Controllers;

use App\Models\DashboardItem;
use App\Models\UserDashboardItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;

class DashboardItemController extends Controller
{
    public function getDashboardItems(Request $request)
    {
        $validated = $request->validate([
            'is_active' => ['nullable', 'boolean'],
            'category' => ['nullable', 'string', 'max:100'],
            'widget_type' => ['nullable', 'string', 'max:50'],
        ]);

        try {
            $items = DashboardItem::query()
                ->when(array_key_exists('is_active', $validated), fn ($query) => $query->where('is_active', $validated['is_active']))
                ->when($validated['category'] ?? null, fn ($query, $category) => $query->where('category', $category))
                ->when($validated['widget_type'] ?? null, fn ($query, $widgetType) => $query->where('widget_type', $widgetType))
                ->orderBy('category')
                ->orderBy('sort_order')
                ->orderBy('title')
                ->get();

            return response()->json([
                'success' => 1,
                'dashboard_items' => $items,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => 0,
                'message' => 'Failed to retrieve dashboard items.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function getMyDashboardItems(Request $request)
    {
        try {
            $user = $request->user()
                ?: auth('api')->user()
                ?: auth('web_api')->user()
                ?: auth()->user();

            if (!$user) {
                return response()->json([
                    'success' => 0,
                    'message' => 'Unauthorized.',
                ], 401);
            }

            $user->loadMissing([
                'role.dashboardItems' => fn ($query) => $query
                    ->where('dashboard_items.is_active', true)
                    ->orderBy('role_dashboard_items.sort_order')
                    ->orderBy('dashboard_items.sort_order'),
            ]);

            $roleName = $user->role?->name;
            $normalizedRoleName = str_replace(['-', ' '], '_', strtolower(trim((string) $roleName)));

            $items = $normalizedRoleName === 'super_admin'
                ? DashboardItem::query()
                    ->where('is_active', true)
                    ->orderBy('category')
                    ->orderBy('sort_order')
                    ->get()
                : $user->role?->dashboardItems
                    ?->filter(fn ($item) => (bool) ($item->pivot?->is_visible ?? true))
                    ->values() ?? collect();

            $overrides = UserDashboardItem::query()
                ->where('user_id', $user->id)
                ->get()
                ->keyBy('dashboard_item_id');

            return response()->json([
                'success' => 1,
                'dashboard_items' => $items
                    ->map(fn ($item) => $this->dashboardItemPayload($item, $overrides->get($item->id)))
                    ->sortBy('sort_order')
                    ->values(),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => 0,
                'message' => 'Failed to retrieve allowed dashboard items.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function getQuestionTypeSummaryByBook(Request $request)
    {
        $validated = $request->validate([
            'book_id' => ['required', 'integer', 'exists:book_tbl,id'],
        ]);

        try {
            $book = DB::table('book_tbl')
                ->select('id', 'book_name')
                ->where('id', $validated['book_id'])
                ->first();

            $counts = $this->questionTypeCountsQuery()
                ->leftJoin('book_unit_topic_tbl as topics', 'topics.id', '=', 'q.topic_id')
                ->leftJoin('book_unit_tbl as units', 'units.id', '=', 'topics.unit_id')
                ->where(function ($query) use ($validated) {
                    $query->where('q.book_id', $validated['book_id'])
                        ->orWhere('units.book_id', $validated['book_id']);
                })
                ->groupBy('q.question_type', 'question_types.type_name')
                ->get()
                ->keyBy('question_type_id');

            $summary = $this->questionTypeSummary($counts);

            return response()->json([
                'success' => 1,
                'book' => $book,
                'total_questions' => (int) $summary->sum('total_questions'),
                'question_type_summary' => $summary->values(),
                'chart' => [
                    'type' => 'bar',
                    'labels' => $summary->pluck('question_type')->values(),
                    'datasets' => [
                        [
                            'label' => 'Questions',
                            'data' => $summary->pluck('total_questions')->values(),
                        ],
                    ],
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => 0,
                'message' => 'Failed to retrieve book question type summary.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function getQuestionTypeSummaryByUnit(Request $request)
    {
        $validated = $request->validate([
            'book_id' => ['required', 'integer', 'exists:book_tbl,id'],
        ]);

        try {
            $book = DB::table('book_tbl')
                ->select('id', 'book_name')
                ->where('id', $validated['book_id'])
                ->first();

            $units = DB::table('book_unit_tbl')
                ->select('id', 'unit_no', 'unit_name')
                ->where('book_id', $validated['book_id'])
                ->orderBy('unit_no')
                ->orderBy('unit_name')
                ->get();

            $questionTypes = $this->questionTypes();
            $unitIds = $units->pluck('id')->all();

            $rows = collect();

            if (!empty($unitIds)) {
                $rows = $this->questionTypeCountsQuery()
                    ->addSelect(DB::raw('COALESCE(q.unit_id, topics.unit_id) as unit_id'))
                    ->leftJoin('book_unit_topic_tbl as topics', 'topics.id', '=', 'q.topic_id')
                    ->whereIn(DB::raw('COALESCE(q.unit_id, topics.unit_id)'), $unitIds)
                    ->groupBy(DB::raw('COALESCE(q.unit_id, topics.unit_id)'), 'q.question_type', 'question_types.type_name')
                    ->get();
            }

            $countsByUnit = $rows
                ->groupBy(fn ($row) => (int) $row->unit_id)
                ->map(fn ($unitRows) => $unitRows->keyBy('question_type_id'));

            $unitSummaries = $units
                ->map(function ($unit) use ($countsByUnit, $questionTypes) {
                    $summary = $this->questionTypeSummary(
                        $countsByUnit->get((int) $unit->id, collect()),
                        $questionTypes
                    );

                    return [
                        'unit_id' => (int) $unit->id,
                        'unit_no' => (int) $unit->unit_no,
                        'unit_name' => $unit->unit_name,
                        'total_questions' => (int) $summary->sum('total_questions'),
                        'question_type_summary' => $summary->values(),
                    ];
                })
                ->values();

            $datasets = $questionTypes
                ->map(fn ($type) => [
                    'label' => $type->type_name,
                    'question_type_id' => (int) $type->id,
                    'data' => $unitSummaries
                        ->map(function ($unit) use ($type) {
                            $summary = collect($unit['question_type_summary'])
                                ->firstWhere('question_type_id', (int) $type->id);

                            return (int) ($summary['total_questions'] ?? 0);
                        })
                        ->values(),
                ])
                ->values();

            return response()->json([
                'success' => 1,
                'book' => $book,
                'total_questions' => (int) $unitSummaries->sum('total_questions'),
                'units' => $unitSummaries,
                'chart' => [
                    'type' => 'bar',
                    'labels' => $unitSummaries
                        ->map(fn ($unit) => trim($unit['unit_no'] . '. ' . $unit['unit_name']))
                        ->values(),
                    'unit_ids' => $unitSummaries->pluck('unit_id')->values(),
                    'unit_numbers' => $unitSummaries->pluck('unit_no')->values(),
                    'datasets' => $datasets,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => 0,
                'message' => 'Failed to retrieve unit question type summary.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function getQuestionTypeSummaryByCreator(Request $request)
    {
        $validated = $request->validate([
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        try {
            $questionTypes = $this->questionTypes();

            $rows = $this->questionTypeCountsQuery()
                ->addSelect([
                    'q.created_by',
                    DB::raw('COALESCE(users.name, "Unknown User") as user_name'),
                ])
                ->leftJoin('users', 'users.id', '=', 'q.created_by')
                ->whereBetween('q.created_at', [
                    $validated['start_date'] . ' 00:00:00',
                    $validated['end_date'] . ' 23:59:59',
                ])
                ->when($validated['user_id'] ?? null, fn ($query, $userId) => $query->where('q.created_by', $userId))
                ->groupBy('q.created_by', 'users.name', 'q.question_type', 'question_types.type_name')
                ->orderBy('users.name')
                ->get();

            $users = $rows
                ->groupBy(fn ($row) => $row->created_by ?: 0)
                ->map(function ($userRows) use ($questionTypes) {
                    $first = $userRows->first();
                    $counts = $userRows->keyBy('question_type_id');
                    $summary = $this->questionTypeSummary($counts, $questionTypes);

                    return [
                        'user_id' => $first->created_by ? (int) $first->created_by : null,
                        'user_name' => $first->user_name,
                        'total_questions' => (int) $summary->sum('total_questions'),
                        'question_type_summary' => $summary->values(),
                    ];
                })
                ->sortBy('user_name')
                ->values();

            $datasets = $questionTypes
                ->map(fn ($type) => [
                    'label' => $type->type_name,
                    'question_type_id' => (int) $type->id,
                    'data' => $users
                        ->map(function ($user) use ($type) {
                            $summary = collect($user['question_type_summary'])
                                ->firstWhere('question_type_id', (int) $type->id);

                            return (int) ($summary['total_questions'] ?? 0);
                        })
                        ->values(),
                ])
                ->values();

            return response()->json([
                'success' => 1,
                'filters' => [
                    'start_date' => $validated['start_date'],
                    'end_date' => $validated['end_date'],
                    'user_id' => $validated['user_id'] ?? null,
                ],
                'total_questions' => (int) $users->sum('total_questions'),
                'users' => $users,
                'chart' => [
                    'type' => 'bar',
                    'labels' => $users->pluck('user_name')->values(),
                    'datasets' => $datasets,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => 0,
                'message' => 'Failed to retrieve creator question type summary.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function getOverAllSummary(Request $request){
        try{

            $user = Auth::user();

            $result = DB::select("CALL GetStudentPerformanceSummary(?, ?)", [$user->id, $request->subject_id]);

            return response()->json([
                'success' => 1,
                'stats' => $result,
                'user' => $user,
                'subject' => $request->subject_id
            ]);

        }catch(\Exception $e){
            return response()->json([
                'success' => 0,
                'error' => $e->getMessage(),
            ]);

        }
    }

    public function getSubjectLeaderBoard(Request $request){
        try{
            $user = Auth::user();

            $result = DB::select("CALL GetSubjectLeaderBoard(?)", [$user->id]);

            return response()->json([
                'success' => 1,
                'stats' => $result,
               
            ]);


        }catch(\Exception $e){
            return response()->json([
                'success' => 0,
                'error' => $e->getMessage(),
            ]);

        }
    }

    public function getTestHistory(Request $request){
        try{
            $user = Auth::user();

            $result = DB::select("CALL GetTestHistory(?)", [$user->id]);

            return response()->json([
                'success' => 1,
                'history' => $result,
               
            ]);


        }catch(\Exception $e){
            return response()->json([
                'success' => 0,
                'error' => $e->getMessage(),
            ]);

        }
    }

    private function dashboardItemPayload(DashboardItem $item, ?UserDashboardItem $override = null): array
    {
        return [
            'id' => $item->id,
            'code' => $item->code,
            'title' => $item->title,
            'category' => $item->category,
            'widget_type' => $item->widget_type,
            'data_key' => $item->data_key,
            'permission_name' => $item->permission_name,
            'width' => $override?->width ?: $item->width,
            'sort_order' => (int) ($override?->sort_order ?? $item->pivot?->sort_order ?? $item->sort_order ?? 0),
            'is_visible' => (bool) ($override?->is_visible ?? $item->pivot?->is_visible ?? true),
            'description' => $item->description,
            'settings' => $override?->settings ?: ($item->pivot?->settings
                ? json_decode($item->pivot->settings, true)
                : $item->settings),
            'has_user_override' => (bool) $override,
            'value' => $this->dashboardValue($item->data_key),
        ];
    }
    private function dashboardValue(?string $dataKey)
    {
        if (!$dataKey) {
            return null;
        }

        $resolvers = [
            'admin_users.total' => fn () => $this->countTable('users'),
            'admin_users.active' => fn () => $this->countWhere('users', 'is_active', true),
            'web_users.total' => fn () => $this->countTable('web_users'),
            'web_users.unverified' => fn () => $this->countNull('web_users', 'email_verified_at'),
            'subscriptions.active' => fn () => $this->countWhere('user_subscriptions', 'status', 'active'),
            'payments.pending_requests' => fn () => $this->countWhere('subscription_payment_requests', 'status', 'pending'),
            'payments.approved_requests' => fn () => $this->countWhere('subscription_payment_requests', 'status', 'approved'),
            'classes.total' => fn () => $this->countTable('class_tbl'),
            'curriculum_boards.total' => fn () => $this->countTable('curriculum_board_tbl'),
            'subjects.total' => fn () => $this->countTable('subject_tbl'),
            'books.total' => fn () => $this->countTable('book_tbl'),
            'units.total' => fn () => $this->countTable('book_unit_tbl'),
            'topics.total' => fn () => $this->countTable('book_unit_topic_tbl'),
            'questions.total' => fn () => $this->countTable('exam_question_tbl'),
            'questions.active' => fn () => $this->countActiveQuestions(),
            'questions.mcq' => fn () => $this->countWhere('exam_question_tbl', 'is_mcq', true),
            'questions.alp' => fn () => $this->countWhere('exam_question_tbl', 'is_alp_question', true),
            'questions.diagram' => fn () => $this->countWhere('exam_question_tbl', 'has_diagram', true),
            'tests.total' => fn () => $this->countTable('tests'),
            'test_attempts.total' => fn () => $this->countTable('test_attempts'),
            'news.total' => fn () => $this->countFirstAvailableTable(['news', 'news_tbl']),
            'news.published' => fn () => $this->countPublishedNews(),
            'news.featured' => fn () => $this->countFeaturedNews(),
            'news_categories.total' => fn () => $this->countFirstAvailableTable(['news_categories', 'news_category_tbl']),
            'news_tickers.total' => fn () => $this->countTable('news_tickers'),
            'workshops.total' => fn () => $this->countTable('workshops'),
            'workshops.active' => fn () => $this->countWhere('workshops', 'is_published', true),
            'data_entry.assignments.total' => fn () => $this->countTable('data_entry_assignments'),
            'data_entry.assignments.active' => fn () => $this->countWhereIn('data_entry_assignments', 'status', ['assigned', 'in_progress', 'submitted', 'reviewed']),
            'data_entry.items.pending_review' => fn () => $this->countWhere('data_entry_assignment_items', 'status', 'pending_review'),
            'data_entry.items.approved' => fn () => $this->countWhere('data_entry_assignment_items', 'status', 'approved'),
            'data_entry.items.needs_correction' => fn () => $this->countWhere('data_entry_assignment_items', 'status', 'needs_correction'),
            'data_entry.payments.payable' => fn () => $this->sumColumn('data_entry_assignment_payments', 'payable_amount'),
            'data_entry.payments.paid' => fn () => $this->sumColumn('data_entry_assignment_payments', 'paid_amount'),
            'data_entry.payments.balance' => fn () => $this->dataEntryBalance(),
        ];

        return isset($resolvers[$dataKey]) ? $resolvers[$dataKey]() : null;
    }

    private function countTable(string $table): int
    {
        if (!Schema::hasTable($table)) {
            return 0;
        }

        return (int) DB::table($table)->count();
    }

    private function countFirstAvailableTable(array $tables): int
    {
        foreach ($tables as $table) {
            if (Schema::hasTable($table)) {
                return (int) DB::table($table)->count();
            }
        }

        return 0;
    }

    private function countWhere(string $table, string $column, mixed $value): int
    {
        if (!Schema::hasTable($table) || !Schema::hasColumn($table, $column)) {
            return 0;
        }

        return (int) DB::table($table)->where($column, $value)->count();
    }

    private function countWhereIn(string $table, string $column, array $values): int
    {
        if (!Schema::hasTable($table) || !Schema::hasColumn($table, $column)) {
            return 0;
        }

        return (int) DB::table($table)->whereIn($column, $values)->count();
    }

    private function countNull(string $table, string $column): int
    {
        if (!Schema::hasTable($table) || !Schema::hasColumn($table, $column)) {
            return 0;
        }

        return (int) DB::table($table)->whereNull($column)->count();
    }

    private function sumColumn(string $table, string $column): float
    {
        if (!Schema::hasTable($table) || !Schema::hasColumn($table, $column)) {
            return 0;
        }

        return round((float) DB::table($table)->sum($column), 2);
    }

    private function countActiveQuestions(): int
    {
        if (!Schema::hasTable('exam_question_tbl')) {
            return 0;
        }

        if (Schema::hasColumn('exam_question_tbl', 'status')) {
            return $this->countWhere('exam_question_tbl', 'status', 'published');
        }

        return $this->countWhere('exam_question_tbl', 'activate', true);
    }

    private function countPublishedNews(): int
    {
        foreach (['news', 'news_tbl'] as $table) {
            if (!Schema::hasTable($table)) {
                continue;
            }

            if (Schema::hasColumn($table, 'is_published')) {
                return $this->countWhere($table, 'is_published', true);
            }

            if (Schema::hasColumn($table, 'status')) {
                return $this->countWhere($table, 'status', 'published');
            }
        }

        return 0;
    }

    private function countFeaturedNews(): int
    {
        foreach (['news', 'news_tbl'] as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'is_featured')) {
                return $this->countWhere($table, 'is_featured', true);
            }
        }

        return 0;
    }

    private function dataEntryBalance(): float
    {
        return round(
            $this->sumColumn('data_entry_assignment_payments', 'payable_amount')
            - $this->sumColumn('data_entry_assignment_payments', 'paid_amount'),
            2
        );
    }

    private function questionTypeCountsQuery()
    {
        return DB::table('exam_question_tbl as q')
            ->leftJoin('question_type_tbl as question_types', 'question_types.id', '=', 'q.question_type')
            ->select([
                'q.question_type as question_type_id',
                DB::raw('COALESCE(question_types.type_name, CONCAT("Type ", q.question_type)) as question_type'),
                DB::raw('COUNT(q.id) as total_questions'),
            ]);
    }

    private function questionTypes()
    {
        return DB::table('question_type_tbl')
            ->select([
                'id',
                DB::raw('COALESCE(type_name, CONCAT("Type ", id)) as type_name'),
            ])
            ->orderBy('id')
            ->get();
    }

    private function questionTypeSummary($counts, $questionTypes = null)
    {
        $questionTypes = $questionTypes ?: $this->questionTypes();

        return $questionTypes->map(function ($type) use ($counts) {
            $count = $counts->get($type->id);

            return [
                'question_type_id' => (int) $type->id,
                'question_type' => $type->type_name,
                'total_questions' => (int) ($count->total_questions ?? 0),
            ];
        });
    }
}



