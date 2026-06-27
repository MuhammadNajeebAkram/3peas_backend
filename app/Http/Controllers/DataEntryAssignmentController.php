<?php

namespace App\Http\Controllers;

use App\Models\AdminActivityLog;
use App\Models\DataEntryAssignment;
use App\Models\DataEntryAssignmentItem;
use App\Models\DataEntryAssignmentPayment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class DataEntryAssignmentController extends Controller
{
    private const MODULE_TYPES = ['question', 'news', 'blog', 'other'];
    private const PAYMENT_TYPES = ['per_question', 'per_page', 'fixed'];
    private const ASSIGNMENT_STATUSES = ['assigned', 'in_progress', 'submitted', 'reviewed', 'paid', 'cancelled'];
    private const ITEM_STATUSES = ['pending_review', 'approved', 'rejected', 'needs_correction'];

    public function getOperatorDashboard(Request $request)
    {
        $validated = $request->validate([
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $operatorId = $validated['assigned_to'] ?? $this->authenticatedUserId($request);

        if (!$operatorId) {
            return response()->json([
                'success' => 0,
                'message' => 'Unable to resolve data entry operator.',
            ], 422);
        }

        try {
            $operator = DB::table('users')
                ->leftJoin('admin_user_profiles', 'admin_user_profiles.user_id', '=', 'users.id')
                ->where('users.id', $operatorId)
                ->select([
                    'users.id',
                    'users.name',
                    'users.email',
                    'admin_user_profiles.phone',
                    'admin_user_profiles.designation',
                    'admin_user_profiles.department',
                    'admin_user_profiles.bank_name',
                    'admin_user_profiles.bank_account_no',
                    'admin_user_profiles.bank_iban_no',
                ])
                ->first();

            $assignments = DataEntryAssignment::query()
                ->with(['assignedBy:id,name,email'])
                ->withCount([
                    'items',
                    'items as pending_review_items_count' => fn ($query) => $query->where('status', DataEntryAssignmentItem::STATUS_PENDING_REVIEW),
                    'items as approved_items_count' => fn ($query) => $query->where('status', DataEntryAssignmentItem::STATUS_APPROVED),
                    'items as rejected_items_count' => fn ($query) => $query->where('status', DataEntryAssignmentItem::STATUS_REJECTED),
                    'items as correction_items_count' => fn ($query) => $query->where('status', DataEntryAssignmentItem::STATUS_NEEDS_CORRECTION),
                ])
                ->withSum(['items as approved_units' => fn ($query) => $query->where('status', DataEntryAssignmentItem::STATUS_APPROVED)], 'unit_count')
                ->withSum('items as submitted_units', 'unit_count')
                ->withSum('payments as paid_amount', 'paid_amount')
                ->where('assigned_to', $operatorId)
                ->latest()
                ->get();

            $assignmentRows = $assignments
                ->map(fn ($assignment) => $this->assignmentDashboardPayload($this->appendFinancialSummary($assignment)))
                ->values();

            $correctionItems = DataEntryAssignmentItem::query()
                ->with(['assignment:id,title,module_type,payment_type,assigned_to', 'reviewedBy:id,name,email'])
                ->whereHas('assignment', fn ($query) => $query->where('assigned_to', $operatorId))
                ->whereIn('status', [
                    DataEntryAssignmentItem::STATUS_REJECTED,
                    DataEntryAssignmentItem::STATUS_NEEDS_CORRECTION,
                ])
                ->latest('reviewed_at')
                ->limit(10)
                ->get()
                ->map(fn ($item) => [
                    'id' => $item->id,
                    'assignment_id' => $item->assignment_id,
                    'assignment_title' => $item->assignment?->title,
                    'module_type' => $item->module_type,
                    'reference_id' => $item->reference_id,
                    'title' => $item->title,
                    'unit_count' => (float) $item->unit_count,
                    'status' => $item->status,
                    'reviewer_remarks' => $item->reviewer_remarks,
                    'reviewed_by' => $item->reviewedBy ? [
                        'id' => $item->reviewedBy->id,
                        'name' => $item->reviewedBy->name,
                        'email' => $item->reviewedBy->email,
                    ] : null,
                    'reviewed_at' => $item->reviewed_at,
                ])
                ->values();

            $recentPayments = DataEntryAssignmentPayment::query()
                ->with(['assignment:id,title,assigned_to,payment_type', 'paidBy:id,name,email'])
                ->whereHas('assignment', fn ($query) => $query->where('assigned_to', $operatorId))
                ->latest('paid_at')
                ->limit(10)
                ->get()
                ->map(fn ($payment) => [
                    'id' => $payment->id,
                    'assignment_id' => $payment->assignment_id,
                    'assignment_title' => $payment->assignment?->title,
                    'payment_type' => $payment->assignment?->payment_type,
                    'approved_units' => (float) $payment->approved_units,
                    'payable_amount' => (float) $payment->payable_amount,
                    'paid_amount' => (float) $payment->paid_amount,
                    'payment_status' => $payment->payment_status,
                    'payment_method' => $payment->payment_method,
                    'transaction_reference' => $payment->transaction_reference,
                    'remarks' => $payment->remarks,
                    'paid_at' => $payment->paid_at,
                    'paid_by' => $payment->paidBy ? [
                        'id' => $payment->paidBy->id,
                        'name' => $payment->paidBy->name,
                        'email' => $payment->paidBy->email,
                    ] : null,
                ])
                ->values();

            $summary = $assignmentRows->reduce(function (array $carry, array $assignment) {
                $carry['total_assignments']++;
                $carry['active_assignments'] += in_array($assignment['status'], ['assigned', 'in_progress', 'submitted'], true) ? 1 : 0;
                $carry['target_quantity'] += $assignment['target_quantity'] ?? 0;
                $carry['submitted_items'] += $assignment['items_count'];
                $carry['approved_items'] += $assignment['approved_items_count'];
                $carry['pending_review_items'] += $assignment['pending_review_items_count'];
                $carry['rejected_items'] += $assignment['rejected_items_count'];
                $carry['correction_items'] += $assignment['correction_items_count'];
                $carry['approved_units'] += $assignment['progress']['approved_units'];
                $carry['payable_amount'] += $assignment['progress']['payable_amount'];
                $carry['paid_amount'] += $assignment['progress']['paid_amount'];
                $carry['balance_amount'] += $assignment['progress']['balance_amount'];

                return $carry;
            }, [
                'total_assignments' => 0,
                'active_assignments' => 0,
                'target_quantity' => 0,
                'submitted_items' => 0,
                'approved_items' => 0,
                'pending_review_items' => 0,
                'rejected_items' => 0,
                'correction_items' => 0,
                'approved_units' => 0,
                'payable_amount' => 0,
                'paid_amount' => 0,
                'balance_amount' => 0,
            ]);

            return response()->json([
                'success' => 1,
                'operator' => $operator,
                'summary' => [
                    'total_assignments' => $summary['total_assignments'],
                    'active_assignments' => $summary['active_assignments'],
                    'target_quantity' => $summary['target_quantity'],
                    'submitted_items' => $summary['submitted_items'],
                    'approved_items' => $summary['approved_items'],
                    'pending_review_items' => $summary['pending_review_items'],
                    'rejected_items' => $summary['rejected_items'],
                    'correction_items' => $summary['correction_items'],
                    'approved_units' => round($summary['approved_units'], 2),
                    'payable_amount' => round($summary['payable_amount'], 2),
                    'paid_amount' => round($summary['paid_amount'], 2),
                    'balance_amount' => round($summary['balance_amount'], 2),
                ],
                'assignments' => $assignmentRows,
                'correction_items' => $correctionItems,
                'recent_payments' => $recentPayments,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => 0,
                'message' => 'Failed to retrieve data entry dashboard.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function getAssignments(Request $request)
    {
        $validated = $request->validate([
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
            'assigned_by' => ['nullable', 'integer', 'exists:users,id'],
            'module_type' => ['nullable', Rule::in(self::MODULE_TYPES)],
            'payment_type' => ['nullable', Rule::in(self::PAYMENT_TYPES)],
            'status' => ['nullable', Rule::in(self::ASSIGNMENT_STATUSES)],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        try {
            $assignments = DataEntryAssignment::query()
                ->with(['assignedTo:id,name,email', 'assignedBy:id,name,email'])
                ->withCount([
                    'items',
                    'items as pending_review_items_count' => fn ($query) => $query->where('status', DataEntryAssignmentItem::STATUS_PENDING_REVIEW),
                    'items as approved_items_count' => fn ($query) => $query->where('status', DataEntryAssignmentItem::STATUS_APPROVED),
                    'items as rejected_items_count' => fn ($query) => $query->where('status', DataEntryAssignmentItem::STATUS_REJECTED),
                    'items as correction_items_count' => fn ($query) => $query->where('status', DataEntryAssignmentItem::STATUS_NEEDS_CORRECTION),
                ])
                ->withSum(['items as approved_units' => fn ($query) => $query->where('status', DataEntryAssignmentItem::STATUS_APPROVED)], 'unit_count')
                ->withSum('payments as paid_amount', 'paid_amount')
                ->when($validated['assigned_to'] ?? null, fn ($query, $userId) => $query->where('assigned_to', $userId))
                ->when($validated['assigned_by'] ?? null, fn ($query, $userId) => $query->where('assigned_by', $userId))
                ->when($validated['module_type'] ?? null, fn ($query, $moduleType) => $query->where('module_type', $moduleType))
                ->when($validated['payment_type'] ?? null, fn ($query, $paymentType) => $query->where('payment_type', $paymentType))
                ->when($validated['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
                ->when($validated['date_from'] ?? null, fn ($query, $dateFrom) => $query->whereDate('created_at', '>=', $dateFrom))
                ->when($validated['date_to'] ?? null, fn ($query, $dateTo) => $query->whereDate('created_at', '<=', $dateTo))
                ->latest()
                ->paginate($validated['per_page'] ?? 25);

            $assignments->getCollection()->transform(fn ($assignment) => $this->appendFinancialSummary($assignment));

            return response()->json([
                'success' => 1,
                'assignments' => $assignments,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => 0,
                'message' => 'Failed to retrieve data entry assignments.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function getAssignmentById($id)
    {
        try {
            $assignment = DataEntryAssignment::with([
                'assignedTo:id,name,email',
                'assignedBy:id,name,email',
                'items.submittedBy:id,name,email',
                'items.reviewedBy:id,name,email',
                'payments.paidBy:id,name,email',
            ])->find($id);

            if (!$assignment) {
                return response()->json([
                    'success' => 0,
                    'message' => 'Data entry assignment not found.',
                ], 404);
            }

            return response()->json([
                'success' => 1,
                'assignment' => $this->appendFinancialSummary($assignment),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => 0,
                'message' => 'Failed to retrieve data entry assignment.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function saveAssignment(Request $request)
    {
        $validated = $this->validateAssignmentPayload($request);
        $adminId = $this->authenticatedUserId($request);

        try {
            $assignment = DB::transaction(function () use ($validated, $request, $adminId) {
                $assignment = DataEntryAssignment::create([
                    'assigned_to' => $validated['assigned_to'],
                    'assigned_by' => $adminId,
                    'module_type' => $validated['module_type'] ?? 'question',
                    'title' => $validated['title'],
                    'instructions' => $validated['instructions'] ?? null,
                    'target_quantity' => $validated['target_quantity'] ?? null,
                    'payment_type' => $validated['payment_type'],
                    'rate_per_unit' => $validated['payment_type'] === DataEntryAssignment::PAYMENT_FIXED ? 0 : ($validated['rate_per_unit'] ?? 0),
                    'fixed_amount' => $validated['payment_type'] === DataEntryAssignment::PAYMENT_FIXED ? ($validated['fixed_amount'] ?? 0) : 0,
                    'status' => $validated['status'] ?? 'assigned',
                    'due_date' => $validated['due_date'] ?? null,
                ]);

                $this->logActivity($request, 'created', 'data-entry-assignments', DataEntryAssignment::class, $assignment->id, $adminId);

                return $assignment;
            });

            return response()->json([
                'success' => 1,
                'message' => 'Data entry assignment created successfully.',
                'assignment' => $this->appendFinancialSummary($assignment->load(['assignedTo:id,name,email', 'assignedBy:id,name,email'])),
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => 0,
                'message' => 'Failed to create data entry assignment.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function updateAssignment(Request $request, $id)
    {
        $assignment = DataEntryAssignment::find($id);

        if (!$assignment) {
            return response()->json([
                'success' => 0,
                'message' => 'Data entry assignment not found.',
            ], 404);
        }

        $validated = $this->validateAssignmentPayload($request, false);
        $adminId = $this->authenticatedUserId($request);

        try {
            DB::transaction(function () use ($assignment, $validated, $request, $adminId) {
                if (array_key_exists('payment_type', $validated)) {
                    if ($validated['payment_type'] === DataEntryAssignment::PAYMENT_FIXED) {
                        $validated['rate_per_unit'] = 0;
                    } else {
                        $validated['fixed_amount'] = 0;
                    }
                }

                $assignment->update($validated);
                $this->logActivity($request, 'updated', 'data-entry-assignments', DataEntryAssignment::class, $assignment->id, $adminId);
            });

            return response()->json([
                'success' => 1,
                'message' => 'Data entry assignment updated successfully.',
                'assignment' => $this->appendFinancialSummary($assignment->fresh(['assignedTo:id,name,email', 'assignedBy:id,name,email'])),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => 0,
                'message' => 'Failed to update data entry assignment.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function updateAssignmentStatus(Request $request, $id)
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(self::ASSIGNMENT_STATUSES)],
        ]);

        $assignment = DataEntryAssignment::find($id);

        if (!$assignment) {
            return response()->json([
                'success' => 0,
                'message' => 'Data entry assignment not found.',
            ], 404);
        }

        try {
            $assignment->update(['status' => $validated['status']]);
            $this->logActivity($request, 'status_updated', 'data-entry-assignments', DataEntryAssignment::class, $assignment->id);

            return response()->json([
                'success' => 1,
                'message' => 'Assignment status updated successfully.',
                'assignment' => $this->appendFinancialSummary($assignment->fresh()),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => 0,
                'message' => 'Failed to update assignment status.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function addAssignmentItem(Request $request, $id)
    {
        $assignment = DataEntryAssignment::find($id);

        if (!$assignment) {
            return response()->json([
                'success' => 0,
                'message' => 'Data entry assignment not found.',
            ], 404);
        }

        $validated = $request->validate([
            'module_type' => ['nullable', Rule::in(self::MODULE_TYPES)],
            'reference_id' => ['nullable', 'integer', 'min:1'],
            'title' => ['nullable', 'string', 'max:255'],
            'unit_count' => ['nullable', 'numeric', 'min:0.01', 'max:999999.99'],
            'submitted_by' => ['nullable', 'integer', 'exists:users,id'],
            'submitter_notes' => ['nullable', 'string'],
        ]);

        $moduleType = $validated['module_type'] ?? $assignment->module_type;
        $referenceTitle = $this->referenceTitle($moduleType, $validated['reference_id'] ?? null);

        if (($validated['reference_id'] ?? null) && $referenceTitle === null && in_array($moduleType, ['question', 'news'], true)) {
            return response()->json([
                'success' => 0,
                'message' => 'Referenced ' . $moduleType . ' record was not found.',
            ], 422);
        }

        try {
            $item = DB::transaction(function () use ($assignment, $validated, $request, $moduleType, $referenceTitle) {
                $item = DataEntryAssignmentItem::create([
                    'assignment_id' => $assignment->id,
                    'submitted_by' => $validated['submitted_by'] ?? $assignment->assigned_to,
                    'module_type' => $moduleType,
                    'reference_id' => $validated['reference_id'] ?? null,
                    'title' => $validated['title'] ?? $referenceTitle,
                    'unit_count' => $assignment->payment_type === DataEntryAssignment::PAYMENT_PER_QUESTION
                        ? ($validated['unit_count'] ?? 1)
                        : ($validated['unit_count'] ?? 1),
                    'status' => DataEntryAssignmentItem::STATUS_PENDING_REVIEW,
                    'submitter_notes' => $validated['submitter_notes'] ?? null,
                ]);

                if ($assignment->status === 'assigned') {
                    $assignment->update(['status' => 'in_progress']);
                }

                $this->logActivity($request, 'item_added', 'data-entry-assignments', DataEntryAssignmentItem::class, $item->id);

                return $item;
            });

            return response()->json([
                'success' => 1,
                'message' => 'Assignment item added successfully.',
                'item' => $item->load(['submittedBy:id,name,email']),
                'assignment' => $this->appendFinancialSummary($assignment->fresh()),
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => 0,
                'message' => 'Failed to add assignment item.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function reviewAssignmentItem(Request $request, $itemId)
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(self::ITEM_STATUSES)],
            'unit_count' => ['nullable', 'numeric', 'min:0.01', 'max:999999.99'],
            'reviewer_remarks' => ['nullable', 'string'],
        ]);

        $item = DataEntryAssignmentItem::with('assignment')->find($itemId);

        if (!$item) {
            return response()->json([
                'success' => 0,
                'message' => 'Assignment item not found.',
            ], 404);
        }

        try {
            DB::transaction(function () use ($item, $validated, $request) {
                $item->update([
                    'status' => $validated['status'],
                    'unit_count' => $validated['unit_count'] ?? $item->unit_count,
                    'reviewer_remarks' => $validated['reviewer_remarks'] ?? $item->reviewer_remarks,
                    'reviewed_by' => $this->authenticatedUserId($request),
                    'reviewed_at' => now(),
                ]);

                $this->refreshAssignmentReviewStatus($item->assignment);
                $this->logActivity($request, 'item_reviewed', 'data-entry-assignments', DataEntryAssignmentItem::class, $item->id);
            });

            return response()->json([
                'success' => 1,
                'message' => 'Assignment item reviewed successfully.',
                'item' => $item->fresh(['submittedBy:id,name,email', 'reviewedBy:id,name,email']),
                'assignment' => $this->appendFinancialSummary($item->assignment->fresh()),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => 0,
                'message' => 'Failed to review assignment item.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function recordAssignmentPayment(Request $request, $id)
    {
        $assignment = DataEntryAssignment::find($id);

        if (!$assignment) {
            return response()->json([
                'success' => 0,
                'message' => 'Data entry assignment not found.',
            ], 404);
        }

        $validated = $request->validate([
            'paid_amount' => ['required', 'numeric', 'min:0.01', 'max:999999999.99'],
            'payment_method' => ['nullable', 'string', 'max:50'],
            'transaction_reference' => ['nullable', 'string', 'max:255'],
            'remarks' => ['nullable', 'string'],
            'paid_at' => ['nullable', 'date'],
        ]);

        try {
            $payment = DB::transaction(function () use ($assignment, $validated, $request) {
                $summary = $this->financialSummary($assignment);
                $paidAmount = (float) $validated['paid_amount'];
                $newPaidTotal = $summary['paid_amount'] + $paidAmount;
                $paymentStatus = $summary['payable_amount'] > 0 && $newPaidTotal >= $summary['payable_amount']
                    ? 'paid'
                    : 'partial';

                $payment = DataEntryAssignmentPayment::create([
                    'assignment_id' => $assignment->id,
                    'paid_by' => $this->authenticatedUserId($request),
                    'approved_units' => $summary['approved_units'],
                    'payable_amount' => $summary['payable_amount'],
                    'paid_amount' => $paidAmount,
                    'payment_status' => $paymentStatus,
                    'payment_method' => $validated['payment_method'] ?? null,
                    'transaction_reference' => $validated['transaction_reference'] ?? null,
                    'remarks' => $validated['remarks'] ?? null,
                    'paid_at' => $validated['paid_at'] ?? now(),
                ]);

                if ($paymentStatus === 'paid') {
                    $assignment->update(['status' => 'paid']);
                }

                $this->logActivity($request, 'payment_recorded', 'data-entry-assignments', DataEntryAssignmentPayment::class, $payment->id);

                return $payment;
            });

            return response()->json([
                'success' => 1,
                'message' => 'Assignment payment recorded successfully.',
                'payment' => $payment->load('paidBy:id,name,email'),
                'assignment' => $this->appendFinancialSummary($assignment->fresh()),
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => 0,
                'message' => 'Failed to record assignment payment.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    private function validateAssignmentPayload(Request $request, bool $creating = true): array
    {
        $required = $creating ? 'required' : 'sometimes';
        $rateRules = $creating
            ? ['nullable', 'numeric', 'min:0', 'max:999999999.99', 'required_unless:payment_type,fixed']
            : ['nullable', 'numeric', 'min:0', 'max:999999999.99', 'required_if:payment_type,per_question', 'required_if:payment_type,per_page'];
        $fixedRules = $creating
            ? ['nullable', 'numeric', 'min:0', 'max:999999999.99', 'required_if:payment_type,fixed']
            : ['nullable', 'numeric', 'min:0', 'max:999999999.99', 'required_if:payment_type,fixed'];

        return $request->validate([
            'assigned_to' => [$required, 'integer', 'exists:users,id'],
            'module_type' => [$creating ? 'nullable' : 'sometimes', Rule::in(self::MODULE_TYPES)],
            'title' => [$required, 'string', 'max:255'],
            'instructions' => ['nullable', 'string'],
            'target_quantity' => ['nullable', 'integer', 'min:1'],
            'payment_type' => [$required, Rule::in(self::PAYMENT_TYPES)],
            'rate_per_unit' => $rateRules,
            'fixed_amount' => $fixedRules,
            'status' => ['nullable', Rule::in(self::ASSIGNMENT_STATUSES)],
            'due_date' => ['nullable', 'date'],
        ]);
    }

    private function appendFinancialSummary(DataEntryAssignment $assignment): DataEntryAssignment
    {
        $assignment->setAttribute('progress', $this->financialSummary($assignment));

        return $assignment;
    }

    private function assignmentDashboardPayload(DataEntryAssignment $assignment): array
    {
        $targetQuantity = $assignment->target_quantity ? (int) $assignment->target_quantity : null;
        $approvedUnits = (float) ($assignment->progress['approved_units'] ?? 0);
        $submittedUnits = (float) ($assignment->submitted_units ?? $assignment->items_count ?? 0);
        $progressBase = $targetQuantity ?: max($submittedUnits, $approvedUnits, 1);

        return [
            'id' => $assignment->id,
            'title' => $assignment->title,
            'module_type' => $assignment->module_type,
            'payment_type' => $assignment->payment_type,
            'rate_per_unit' => (float) $assignment->rate_per_unit,
            'fixed_amount' => (float) $assignment->fixed_amount,
            'target_quantity' => $targetQuantity,
            'status' => $assignment->status,
            'due_date' => $assignment->due_date,
            'assigned_by' => $assignment->assignedBy ? [
                'id' => $assignment->assignedBy->id,
                'name' => $assignment->assignedBy->name,
                'email' => $assignment->assignedBy->email,
            ] : null,
            'items_count' => (int) ($assignment->items_count ?? 0),
            'pending_review_items_count' => (int) ($assignment->pending_review_items_count ?? 0),
            'approved_items_count' => (int) ($assignment->approved_items_count ?? 0),
            'rejected_items_count' => (int) ($assignment->rejected_items_count ?? 0),
            'correction_items_count' => (int) ($assignment->correction_items_count ?? 0),
            'submitted_units' => round($submittedUnits, 2),
            'progress_percent' => round(min(($approvedUnits / $progressBase) * 100, 100), 2),
            'progress' => $assignment->progress,
            'created_at' => $assignment->created_at,
            'updated_at' => $assignment->updated_at,
        ];
    }

    private function financialSummary(DataEntryAssignment $assignment): array
    {
        $approvedUnits = $assignment->approved_units ?? null;
        $paidAmount = $assignment->paid_amount ?? null;

        if ($approvedUnits === null) {
            $approvedUnits = (float) DataEntryAssignmentItem::where('assignment_id', $assignment->id)
                ->where('status', DataEntryAssignmentItem::STATUS_APPROVED)
                ->sum('unit_count');
        }

        if ($paidAmount === null) {
            $paidAmount = (float) DataEntryAssignmentPayment::where('assignment_id', $assignment->id)
                ->sum('paid_amount');
        }

        $payableAmount = $assignment->payment_type === DataEntryAssignment::PAYMENT_FIXED
            ? (float) $assignment->fixed_amount
            : $approvedUnits * (float) $assignment->rate_per_unit;

        return [
            'approved_units' => round((float) $approvedUnits, 2),
            'payable_amount' => round($payableAmount, 2),
            'paid_amount' => round((float) $paidAmount, 2),
            'balance_amount' => round(max($payableAmount - (float) $paidAmount, 0), 2),
        ];
    }

    private function refreshAssignmentReviewStatus(DataEntryAssignment $assignment): void
    {
        $itemCount = $assignment->items()->count();

        if ($itemCount === 0 || $assignment->status === 'paid') {
            return;
        }

        $pendingCount = $assignment->items()
            ->where('status', DataEntryAssignmentItem::STATUS_PENDING_REVIEW)
            ->count();

        if ($pendingCount === 0) {
            $assignment->update(['status' => 'reviewed']);
        }
    }

    private function referenceTitle(string $moduleType, ?int $referenceId): ?string
    {
        if (!$referenceId) {
            return null;
        }

        if ($moduleType === 'question') {
            return DB::table('exam_question_tbl')->where('id', $referenceId)->value('question');
        }

        if ($moduleType === 'news') {
            return DB::table('news')->where('id', $referenceId)->value('title');
        }

        return null;
    }

    private function authenticatedUserId(Request $request): ?int
    {
        $user = $request->user()
            ?: auth('api')->user()
            ?: auth('web_api')->user()
            ?: auth()->user();

        return $user?->id ? (int) $user->id : null;
    }

    private function logActivity(
        Request $request,
        string $action,
        string $module,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?int $adminUserId = null
    ): void {
        try {
            if (!Schema::hasTable('admin_activity_logs')) {
                return;
            }

            AdminActivityLog::create([
                'admin_user_id' => $adminUserId ?? $this->authenticatedUserId($request),
                'action' => $action,
                'module' => $module,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'ip_address' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 1000),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
