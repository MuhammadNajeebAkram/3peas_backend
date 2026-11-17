<?php

namespace App\Http\Services;

use App\Models\SubscriptionPaymentRequest;
use App\Models\TeacherProfile;
use App\Models\TeacherSettlement;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class TeacherFinancialReportService
{
    public function accessStatus(TeacherProfile $profile): array
    {
        $canCollect = app(TeacherCollectionPermissionService::class)->canCollect($profile);
        $hasPayments = SubscriptionPaymentRequest::where('teacher_profile_id', $profile->id)
            ->where('payment_method', 'teacher')
            ->exists();
        $hasSettlements = TeacherSettlement::where('teacher_profile_id', $profile->id)->exists();
        $hadPermissionEvent = Schema::hasTable('teacher_profile_events')
            && DB::table('teacher_profile_events')
                ->where('teacher_profile_id', $profile->id)
                ->whereIn('action', ['collection_permission_enabled', 'collection_permission_disabled'])
                ->exists();

        $hasHistoricalRecords = $hasPayments || $hasSettlements || $hadPermissionEvent;
        $canAccess = $canCollect || (bool) $profile->can_collect_student_subscriptions || $hasHistoricalRecords;

        return [
            'can_access' => $canAccess,
            'can_collect' => $canCollect,
            'read_only' => !$canCollect,
            'has_historical_records' => $hasHistoricalRecords,
            'collection_code' => $profile->collection_code,
        ];
    }

    public function assertCanAccess(TeacherProfile $profile): array
    {
        $access = $this->accessStatus($profile);
        abort_unless(
            $access['can_access'],
            403,
            'Financial dashboard access requires collection authorization or historical financial records.'
        );
        return $access;
    }

    public function basePaymentsQuery(int $teacherId, array $filters = []): Builder
    {
        $query = SubscriptionPaymentRequest::query()
            ->where('subscription_payment_requests.teacher_profile_id', $teacherId)
            ->where('subscription_payment_requests.payment_method', 'teacher');

        if (!empty($filters['payment_method'])) {
            $query->where('subscription_payment_requests.payment_method', $filters['payment_method']);
        }
        if (!empty($filters['status'])) {
            if ($filters['status'] === 'approved') {
                $query->where('subscription_payment_requests.status', 'approved')
                    ->whereNotNull('subscription_payment_requests.confirmed_at');
            } else {
                $query->where('subscription_payment_requests.status', $filters['status']);
            }
        }
        if (!empty($filters['offered_program_id'])) {
            $query->where('subscription_payment_requests.offered_program_id', (int) $filters['offered_program_id']);
        }
        if (!empty($filters['receipt_number'])) {
            $ref = trim((string) $filters['receipt_number']);
            $query->where(function (Builder $q) use ($ref) {
                $q->where('subscription_payment_requests.receipt_number', 'like', '%'.$ref.'%')
                    ->orWhere('subscription_payment_requests.transaction_id', 'like', '%'.$ref.'%');
            });
        }
        if (!empty($filters['search'])) {
            $search = trim((string) $filters['search']);
            $query->where(function (Builder $q) use ($search) {
                $q->where('subscription_payment_requests.receipt_number', 'like', '%'.$search.'%')
                    ->orWhere('subscription_payment_requests.transaction_id', 'like', '%'.$search.'%')
                    ->orWhereHas('user', fn (Builder $u) => $u->where('name', 'like', '%'.$search.'%'))
                    ->orWhereHas('offeredProgram', fn (Builder $p) => $p->where('title', 'like', '%'.$search.'%'));
            });
        }
        if (!empty($filters['from'])) {
            $query->whereRaw('DATE(COALESCE(subscription_payment_requests.confirmed_at, subscription_payment_requests.created_at)) >= ?', [$filters['from']]);
        }
        if (!empty($filters['to'])) {
            $query->whereRaw('DATE(COALESCE(subscription_payment_requests.confirmed_at, subscription_payment_requests.created_at)) <= ?', [$filters['to']]);
        }

        return $query;
    }

    public function baseSettlementsQuery(int $teacherId, array $filters = []): Builder
    {
        $query = TeacherSettlement::query()
            ->where('teacher_settlements.teacher_profile_id', $teacherId);

        if (!empty($filters['status']) && in_array($filters['status'], ['pending', 'confirmed', 'rejected'], true)) {
            $query->where('teacher_settlements.status', $filters['status']);
        }
        if (!empty($filters['payment_method']) && in_array($filters['payment_method'], ['cash', 'bank', 'jazzcash'], true)) {
            $query->where('teacher_settlements.payment_method', $filters['payment_method']);
        }
        if (!empty($filters['from'])) {
            $query->whereDate('teacher_settlements.paid_at', '>=', $filters['from']);
        }
        if (!empty($filters['to'])) {
            $query->whereDate('teacher_settlements.paid_at', '<=', $filters['to']);
        }

        return $query;
    }

    public function summary(int $teacherId, array $filters = []): array
    {
        $paymentFilters = array_intersect_key($filters, array_flip(['from', 'to', 'offered_program_id', 'receipt_number', 'payment_method']));
        $basePayments = $this->basePaymentsQuery($teacherId, $paymentFilters);

        $confirmedRow = (clone $basePayments)
            ->where('subscription_payment_requests.status', 'approved')
            ->whereNotNull('subscription_payment_requests.confirmed_at')
            ->selectRaw('COUNT(*) as aggregate_count, COALESCE(SUM(final_amount), 0) as aggregate_amount')
            ->first();

        $teacherApprovedRow = (clone $basePayments)
            ->where('subscription_payment_requests.status', 'approved')
            ->whereNotNull('subscription_payment_requests.confirmed_at')
            ->whereNotNull('subscription_payment_requests.confirmed_by_web_user_id')
            ->selectRaw('COUNT(*) as aggregate_count, COALESCE(SUM(final_amount), 0) as aggregate_amount')
            ->first();

        $adminApprovedRow = (clone $basePayments)
            ->where('subscription_payment_requests.status', 'approved')
            ->whereNotNull('subscription_payment_requests.confirmed_at')
            ->whereNotNull('subscription_payment_requests.approved_by')
            ->selectRaw('COUNT(*) as aggregate_count, COALESCE(SUM(final_amount), 0) as aggregate_amount')
            ->first();

        $pendingRow = (clone $basePayments)
            ->where('subscription_payment_requests.status', 'pending')
            ->selectRaw('COUNT(*) as aggregate_count, COALESCE(SUM(final_amount), 0) as aggregate_amount')
            ->first();

        $rejectedRow = (clone $basePayments)
            ->where('subscription_payment_requests.status', 'rejected')
            ->selectRaw('COUNT(*) as aggregate_count, COALESCE(SUM(final_amount), 0) as aggregate_amount')
            ->first();

        $settlementFilters = array_intersect_key($filters, array_flip(['from', 'to']));
        $baseSettlements = $this->baseSettlementsQuery($teacherId, $settlementFilters);

        $confirmedSettlementRow = (clone $baseSettlements)
            ->where('teacher_settlements.status', 'confirmed')
            ->selectRaw('COUNT(*) as aggregate_count, COALESCE(SUM(amount), 0) as aggregate_amount')
            ->first();

        $pendingSettlementRow = (clone $baseSettlements)
            ->where('teacher_settlements.status', 'pending')
            ->selectRaw('COUNT(*) as aggregate_count, COALESCE(SUM(amount), 0) as aggregate_amount')
            ->first();

        $rejectedSettlementRow = (clone $baseSettlements)
            ->where('teacher_settlements.status', 'rejected')
            ->selectRaw('COUNT(*) as aggregate_count, COALESCE(SUM(amount), 0) as aggregate_amount')
            ->first();

        $confirmedCents = TeacherSettlementService::cents($confirmedRow->aggregate_amount ?? 0);
        $teacherApprovedCents = TeacherSettlementService::cents($teacherApprovedRow->aggregate_amount ?? 0);
        $adminApprovedCents = TeacherSettlementService::cents($adminApprovedRow->aggregate_amount ?? 0);
        $pendingCents = TeacherSettlementService::cents($pendingRow->aggregate_amount ?? 0);
        $rejectedCents = TeacherSettlementService::cents($rejectedRow->aggregate_amount ?? 0);

        $remittedCents = TeacherSettlementService::cents($confirmedSettlementRow->aggregate_amount ?? 0);
        $pendingRemittedCents = TeacherSettlementService::cents($pendingSettlementRow->aggregate_amount ?? 0);
        $rejectedRemittedCents = TeacherSettlementService::cents($rejectedSettlementRow->aggregate_amount ?? 0);

        $outstandingCents = $confirmedCents - $remittedCents;
        $availableToRemitCents = $confirmedCents - $remittedCents - $pendingRemittedCents;

        $confirmedCount = (int) ($confirmedRow->aggregate_count ?? 0);
        $pendingCount = (int) ($pendingRow->aggregate_count ?? 0);
        $rejectedCount = (int) ($rejectedRow->aggregate_count ?? 0);
        $remittedCount = (int) ($confirmedSettlementRow->aggregate_count ?? 0);
        $pendingRemittedCount = (int) ($pendingSettlementRow->aggregate_count ?? 0);
        $rejectedRemittedCount = (int) ($rejectedSettlementRow->aggregate_count ?? 0);

        $confirmedMoney = TeacherSettlementService::money($confirmedCents);
        $pendingMoney = TeacherSettlementService::money($pendingCents);
        $rejectedMoney = TeacherSettlementService::money($rejectedCents);
        $remittedMoney = TeacherSettlementService::money($remittedCents);
        $pendingRemittedMoney = TeacherSettlementService::money($pendingRemittedCents);
        $rejectedRemittedMoney = TeacherSettlementService::money($rejectedRemittedCents);
        $outstandingMoney = TeacherSettlementService::money($outstandingCents);
        $availableMoney = TeacherSettlementService::money($availableToRemitCents);

        return [
            'currency' => 'PKR',
            'confirmed_collections' => [
                'count' => $confirmedCount,
                'amount' => $confirmedMoney,
                'teacher_approved_count' => (int) ($teacherApprovedRow->aggregate_count ?? 0),
                'teacher_approved_amount' => TeacherSettlementService::money($teacherApprovedCents),
                'admin_approved_count' => (int) ($adminApprovedRow->aggregate_count ?? 0),
                'admin_approved_amount' => TeacherSettlementService::money($adminApprovedCents),
            ],
            'pending_payments' => [
                'count' => $pendingCount,
                'amount' => $pendingMoney,
            ],
            'approved_payments' => [
                'count' => $confirmedCount,
                'amount' => $confirmedMoney,
            ],
            'rejected_payments' => [
                'count' => $rejectedCount,
                'amount' => $rejectedMoney,
            ],
            'total_remitted' => [
                'count' => $remittedCount,
                'amount' => $remittedMoney,
            ],
            'pending_remittances' => [
                'count' => $pendingRemittedCount,
                'amount' => $pendingRemittedMoney,
            ],
            'rejected_remittances' => [
                'count' => $rejectedRemittedCount,
                'amount' => $rejectedRemittedMoney,
            ],
            'outstanding_balance' => [
                'supported' => true,
                'amount' => $outstandingMoney,
            ],
            'available_to_remit' => [
                'supported' => true,
                'amount' => $availableMoney,
            ],
            // Direct reconciliation keys matching TeacherSettlementService::summary
            'collected' => $confirmedMoney,
            'settled' => $remittedMoney,
            'outstanding' => $outstandingMoney,
            'pending_settlement' => $pendingRemittedMoney,
            'available_to_settle' => $availableMoney,
            'pending_payment_requests' => $pendingCount,
            'confirmed_payment_requests' => $confirmedCount,
            'rejected_payment_requests' => $rejectedCount,
        ];
    }

    public function chart(int $teacherId, array $filters = []): array
    {
        $groupBy = $filters['group_by'] ?? 'day';
        $query = $this->basePaymentsQuery($teacherId, array_merge($filters, ['status' => 'approved']));

        $dailyRows = $query
            ->selectRaw('DATE(subscription_payment_requests.confirmed_at) as bucket_date, COUNT(*) as total_count, COALESCE(SUM(subscription_payment_requests.final_amount), 0) as total_amount')
            ->groupByRaw('DATE(subscription_payment_requests.confirmed_at)')
            ->orderBy('bucket_date', 'asc')
            ->get();

        $buckets = [];
        $totalCount = 0;
        $totalCents = 0;

        foreach ($dailyRows as $row) {
            if (!$row->bucket_date) {
                continue;
            }
            [$periodKey, $startDate, $endDate] = $this->resolvePeriodBucket((string) $row->bucket_date, $groupBy);
            if (!isset($buckets[$periodKey])) {
                $buckets[$periodKey] = [
                    'period' => $periodKey,
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                    'confirmed_count' => 0,
                    'confirmed_cents' => 0,
                ];
            }
            $rowCount = (int) $row->total_count;
            $rowCents = TeacherSettlementService::cents($row->total_amount);
            $buckets[$periodKey]['confirmed_count'] += $rowCount;
            $buckets[$periodKey]['confirmed_cents'] += $rowCents;
            $totalCount += $rowCount;
            $totalCents += $rowCents;
        }

        $series = array_values(array_map(fn (array $b) => [
            'period' => $b['period'],
            'start_date' => $b['start_date'],
            'end_date' => $b['end_date'],
            'confirmed_count' => $b['confirmed_count'],
            'confirmed_amount' => TeacherSettlementService::money($b['confirmed_cents']),
        ], $buckets));

        return [
            'currency' => 'PKR',
            'group_by' => $groupBy,
            'from' => $filters['from'] ?? null,
            'to' => $filters['to'] ?? null,
            'total_confirmed_count' => $totalCount,
            'total_confirmed_amount' => TeacherSettlementService::money($totalCents),
            'series' => $series,
        ];
    }

    public function payments(int $teacherId, array $filters = [], int $perPage = 20)
    {
        $settlements = app(TeacherSettlementService::class);
        $query = $this->basePaymentsQuery($teacherId, $filters)
            ->select([
                'subscription_payment_requests.id',
                'subscription_payment_requests.user_id',
                'subscription_payment_requests.offered_program_id',
                'subscription_payment_requests.subscription_id',
                'subscription_payment_requests.teacher_profile_id',
                'subscription_payment_requests.collection_code_snapshot',
                'subscription_payment_requests.price',
                'subscription_payment_requests.discount_amount',
                'subscription_payment_requests.discount_percentage',
                'subscription_payment_requests.final_amount',
                'subscription_payment_requests.payment_method',
                'subscription_payment_requests.transaction_id',
                'subscription_payment_requests.receipt_number',
                'subscription_payment_requests.proof_file_path',
                'subscription_payment_requests.status',
                'subscription_payment_requests.created_at',
                'subscription_payment_requests.updated_at',
                'subscription_payment_requests.confirmed_at',
                'subscription_payment_requests.approved_at',
                'subscription_payment_requests.confirmed_by_web_user_id',
                'subscription_payment_requests.approved_by',
                'subscription_payment_requests.rejected_by_web_user_id',
                'subscription_payment_requests.rejected_by',
                'subscription_payment_requests.rejection_reason',
                'subscription_payment_requests.admin_remarks',
            ])
            ->selectSub(
                $settlements->allocations(['confirmed'])
                    ->whereColumn('a.payment_request_id', 'subscription_payment_requests.id')
                    ->selectRaw('COALESCE(SUM(a.amount), 0)'),
                'settled_amount'
            )
            ->selectSub(
                $settlements->allocations(['pending'])
                    ->whereColumn('a.payment_request_id', 'subscription_payment_requests.id')
                    ->selectRaw('COALESCE(SUM(a.amount), 0)'),
                'pending_settlement_amount'
            )
            ->with([
                'user:id,name,email,phone',
                'offeredProgram:id,title,offered_class_id',
                'offeredProgram.offeredClass:id,class_id,session_start,session_end',
                'offeredProgram.offeredClass.userClass:id,class_name',
            ])
            ->orderByDesc('subscription_payment_requests.id');

        $paginator = $query->paginate($perPage);
        $paginator->getCollection()->transform(fn (SubscriptionPaymentRequest $row) => $this->formatPaymentRow($row));

        return $paginator;
    }

    public function paymentDetail(int $teacherId, int $paymentId): array
    {
        $settlements = app(TeacherSettlementService::class);
        $payment = SubscriptionPaymentRequest::query()
            ->where('subscription_payment_requests.teacher_profile_id', $teacherId)
            ->where('subscription_payment_requests.payment_method', 'teacher')
            ->select('subscription_payment_requests.*')
            ->selectSub(
                $settlements->allocations(['confirmed'])
                    ->whereColumn('a.payment_request_id', 'subscription_payment_requests.id')
                    ->selectRaw('COALESCE(SUM(a.amount), 0)'),
                'settled_amount'
            )
            ->selectSub(
                $settlements->allocations(['pending'])
                    ->whereColumn('a.payment_request_id', 'subscription_payment_requests.id')
                    ->selectRaw('COALESCE(SUM(a.amount), 0)'),
                'pending_settlement_amount'
            )
            ->with([
                'user:id,name,email,phone',
                'teacherProfile.webUser:id,name,email',
                'offeredProgram.offeredClass.userClass',
                'userSubscription:id,user_id,offered_program_id,status,access_type,price_paid,started_at,expires_at',
            ])
            ->findOrFail($paymentId);

        $formatted = $this->formatPaymentRow($payment);
        $events = DB::table('subscription_payment_request_events')
            ->where('subscription_payment_request_id', $payment->id)
            ->orderBy('id', 'asc')
            ->get()
            ->map(function ($event) {
                $event->details = is_string($event->details) ? json_decode($event->details, true) : $event->details;
                return $event;
            })
            ->values()
            ->all();

        $rejectedEvent = collect($events)->where('event_type', 'rejected')->last();
        $formatted['rejection_date'] = $payment->status === 'rejected'
            ? ($rejectedEvent->created_at ?? optional($payment->updated_at)->toISOString())
            : null;
        $formatted['review_history'] = $events;
        $formatted['teacher_attribution'] = [
            'teacher_profile_id' => $payment->teacher_profile_id,
            'teacher_code' => $payment->teacherProfile?->teacher_code,
            'teacher_name' => $payment->teacherProfile?->webUser?->name,
            'collection_code_snapshot' => $payment->collection_code_snapshot,
        ];

        return $formatted;
    }

    public function formatPaymentRow(SubscriptionPaymentRequest $row): array
    {
        $hasProof = filled($row->getRawOriginal('proof_file_path') ?? $row->proof_file_path);
        $finalCents = TeacherSettlementService::cents($row->final_amount ?? 0);
        $settledCents = TeacherSettlementService::cents($row->settled_amount ?? 0);
        $pendingSettlementCents = TeacherSettlementService::cents($row->pending_settlement_amount ?? 0);
        $availableCents = ($row->status === 'approved' && $row->confirmed_at !== null)
            ? max(0, $finalCents - $settledCents - $pendingSettlementCents)
            : 0;

        $reviewerType = null;
        if ($row->status === 'approved') {
            $reviewerType = $row->approved_by !== null ? 'admin' : ($row->confirmed_by_web_user_id !== null ? 'teacher' : null);
        } elseif ($row->status === 'rejected') {
            $reviewerType = $row->rejected_by !== null ? 'admin' : ($row->rejected_by_web_user_id !== null ? 'teacher' : null);
        }

        return [
            'id' => $row->id,
            'receipt_number' => $row->receipt_number,
            'transaction_id' => $row->transaction_id,
            'user_id' => $row->user_id,
            'user' => $row->relationLoaded('user') && $row->user ? [
                'id' => $row->user->id,
                'name' => $row->user->name,
                'email' => $row->user->email,
                'phone' => $row->user->phone,
            ] : null,
            'offered_program_id' => $row->offered_program_id,
            'offered_program' => $row->relationLoaded('offeredProgram') && $row->offeredProgram ? [
                'id' => $row->offeredProgram->id,
                'title' => $row->offeredProgram->title,
                'class_name' => $row->offeredProgram->offeredClass?->userClass?->class_name,
                'session_start' => $row->offeredProgram->offeredClass?->session_start,
                'session_end' => $row->offeredProgram->offeredClass?->session_end,
            ] : null,
            'teacher_profile_id' => $row->teacher_profile_id,
            'collection_code_snapshot' => $row->collection_code_snapshot,
            'currency' => 'PKR',
            'price' => TeacherSettlementService::money(TeacherSettlementService::cents($row->price ?? 0)),
            'discount_amount' => TeacherSettlementService::money(TeacherSettlementService::cents($row->discount_amount ?? 0)),
            'discount_percentage' => number_format((float) ($row->discount_percentage ?? 0), 2, '.', ''),
            'final_amount' => TeacherSettlementService::money($finalCents),
            'settled_amount' => TeacherSettlementService::money($settledCents),
            'pending_settlement_amount' => TeacherSettlementService::money($pendingSettlementCents),
            'available_to_remit_amount' => TeacherSettlementService::money($availableCents),
            'payment_method' => $row->payment_method,
            'status' => $row->status,
            'reviewer_type' => $reviewerType,
            'rejection_reason' => $row->rejection_reason,
            'admin_remarks' => $row->admin_remarks,
            'created_at' => optional($row->created_at)->toISOString() ?? (string) $row->created_at,
            'confirmed_at' => optional($row->confirmed_at)->toISOString() ?? ($row->confirmed_at ? (string) $row->confirmed_at : null),
            'approved_at' => $row->approved_at ? (string) $row->approved_at : null,
            'has_proof' => $hasProof,
            'proof_endpoint' => $hasProof ? url('/api/v1/auth/teacher/payment-requests/'.$row->id.'/proof') : null,
        ];
    }

    public function settlementSummary(int $teacherId, array $filters = []): array
    {
        $summary = $this->summary($teacherId, $filters);

        return [
            'currency' => 'PKR',
            'confirmed_collections' => $summary['confirmed_collections'],
            'confirmed_remittances' => $summary['total_remitted'],
            'pending_remittances' => $summary['pending_remittances'],
            'rejected_remittances' => $summary['rejected_remittances'],
            'outstanding_balance' => $summary['outstanding_balance'],
            'available_to_remit' => $summary['available_to_remit'],
            'collected' => $summary['collected'],
            'settled' => $summary['settled'],
            'outstanding' => $summary['outstanding'],
            'pending_settlement' => $summary['pending_settlement'],
            'available_to_settle' => $summary['available_to_settle'],
            'pending_payment_requests' => $summary['pending_payment_requests'],
            'confirmed_payment_requests' => $summary['confirmed_payment_requests'],
        ];
    }

    public function settlements(int $teacherId, array $filters = [], int $perPage = 20)
    {
        $paginator = $this->baseSettlementsQuery($teacherId, $filters)
            ->with([
                'allocations.paymentRequest:id,user_id,offered_program_id,final_amount,receipt_number,confirmed_at',
                'allocations.paymentRequest.user:id,name',
                'allocations.paymentRequest.offeredProgram:id,title',
                'paymentAccount:id,title,bank_name,account_title,account_number,method',
            ])
            ->orderByDesc('teacher_settlements.id')
            ->paginate($perPage);

        $paginator->getCollection()->transform(fn (TeacherSettlement $settlement) => $this->formatSettlementRow($settlement));

        return $paginator;
    }

    public function formatSettlementRow(TeacherSettlement $settlement): array
    {
        $hasProof = $settlement->has_proof;
        return [
            'id' => $settlement->id,
            'settlement_reference' => sprintf('SET-%06d', $settlement->id),
            'submission_key' => $settlement->submission_key,
            'teacher_profile_id' => $settlement->teacher_profile_id,
            'currency' => $settlement->currency ?: 'PKR',
            'amount' => TeacherSettlementService::money(TeacherSettlementService::cents($settlement->amount ?? 0)),
            'payment_method' => $settlement->payment_method,
            'payment_account_id' => $settlement->payment_account_id,
            'payment_account' => $settlement->relationLoaded('paymentAccount') && $settlement->paymentAccount ? [
                'id' => $settlement->paymentAccount->id,
                'title' => $settlement->paymentAccount->title,
                'bank_name' => $settlement->paymentAccount->bank_name,
                'account_title' => $settlement->paymentAccount->account_title,
                'account_number' => $settlement->paymentAccount->account_number,
                'method' => $settlement->paymentAccount->method,
            ] : null,
            'transaction_reference' => $settlement->transaction_reference,
            'paid_at' => $settlement->paid_at ? $settlement->paid_at->toDateString() : null,
            'created_at' => optional($settlement->created_at)->toISOString() ?? (string) $settlement->created_at,
            'reviewed_at' => optional($settlement->reviewed_at)->toISOString() ?? ($settlement->reviewed_at ? (string) $settlement->reviewed_at : null),
            'status' => $settlement->status,
            'notes' => $settlement->notes,
            'rejection_reason' => $settlement->rejection_reason,
            'admin_remarks' => $settlement->rejection_reason,
            'has_proof' => $hasProof,
            'proof_endpoint' => $hasProof ? url('/api/v1/auth/teacher/settlements/'.$settlement->id.'/proof') : null,
            'allocations' => $settlement->relationLoaded('allocations')
                ? $settlement->allocations->map(fn ($a) => [
                    'id' => $a->id,
                    'payment_request_id' => $a->payment_request_id,
                    'amount' => TeacherSettlementService::money(TeacherSettlementService::cents($a->amount ?? 0)),
                    'receipt_number' => $a->paymentRequest?->receipt_number,
                    'student_name' => $a->paymentRequest?->user?->name,
                    'program_title' => $a->paymentRequest?->offeredProgram?->title,
                ])->values()->all()
                : [],
        ];
    }

    public function report(int $teacherId, array $filters = []): array
    {
        $groupBy = $filters['group_by'] ?? 'day';
        $paymentRows = $this->basePaymentsQuery($teacherId, $filters)
            ->selectRaw("
                DATE(COALESCE(subscription_payment_requests.confirmed_at, subscription_payment_requests.created_at)) as bucket_date,
                subscription_payment_requests.status as req_status,
                CASE WHEN subscription_payment_requests.status = 'approved' AND subscription_payment_requests.confirmed_at IS NOT NULL THEN 1 ELSE 0 END as is_confirmed,
                COUNT(*) as total_count,
                COALESCE(SUM(subscription_payment_requests.final_amount), 0) as total_amount
            ")
            ->groupByRaw("
                DATE(COALESCE(subscription_payment_requests.confirmed_at, subscription_payment_requests.created_at)),
                subscription_payment_requests.status,
                CASE WHEN subscription_payment_requests.status = 'approved' AND subscription_payment_requests.confirmed_at IS NOT NULL THEN 1 ELSE 0 END
            ")
            ->orderBy('bucket_date', 'asc')
            ->get();

        $includeSettlements = empty($filters['offered_program_id']) && empty($filters['receipt_number']);
        $settlementRows = $includeSettlements
            ? $this->baseSettlementsQuery($teacherId, array_intersect_key($filters, array_flip(['from', 'to'])))
                ->where('teacher_settlements.status', 'confirmed')
                ->selectRaw('DATE(teacher_settlements.paid_at) as bucket_date, COUNT(*) as total_count, COALESCE(SUM(teacher_settlements.amount), 0) as total_amount')
                ->groupByRaw('DATE(teacher_settlements.paid_at)')
                ->orderBy('bucket_date', 'asc')
                ->get()
            : collect();

        $buckets = [];
        $ensureBucket = function (string $dateStr) use (&$buckets, $groupBy): string {
            [$periodKey, $startDate, $endDate] = $this->resolvePeriodBucket($dateStr, $groupBy);
            if (!isset($buckets[$periodKey])) {
                $buckets[$periodKey] = [
                    'period' => $periodKey,
                    'date' => $startDate,
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                    'total_payment_requests' => 0,
                    'confirmed_payment_count' => 0,
                    'confirmed_collection_cents' => 0,
                    'pending_request_count' => 0,
                    'pending_request_cents' => 0,
                    'rejected_request_count' => 0,
                    'rejected_request_cents' => 0,
                    'confirmed_remittance_count' => 0,
                    'confirmed_remittance_cents' => 0,
                ];
            }
            return $periodKey;
        };

        foreach ($paymentRows as $row) {
            if (!$row->bucket_date) {
                continue;
            }
            $key = $ensureBucket((string) $row->bucket_date);
            $count = (int) $row->total_count;
            $cents = TeacherSettlementService::cents($row->total_amount);

            if ((int) $row->is_confirmed === 1) {
                $buckets[$key]['confirmed_payment_count'] += $count;
                $buckets[$key]['confirmed_collection_cents'] += $cents;
                $buckets[$key]['total_payment_requests'] += $count;
            } elseif ($row->req_status === 'pending') {
                $buckets[$key]['pending_request_count'] += $count;
                $buckets[$key]['pending_request_cents'] += $cents;
                $buckets[$key]['total_payment_requests'] += $count;
            } elseif ($row->req_status === 'rejected') {
                $buckets[$key]['rejected_request_count'] += $count;
                $buckets[$key]['rejected_request_cents'] += $cents;
                $buckets[$key]['total_payment_requests'] += $count;
            }
        }

        foreach ($settlementRows as $row) {
            if (!$row->bucket_date) {
                continue;
            }
            $key = $ensureBucket((string) $row->bucket_date);
            $buckets[$key]['confirmed_remittance_count'] += (int) $row->total_count;
            $buckets[$key]['confirmed_remittance_cents'] += TeacherSettlementService::cents($row->total_amount);
        }

        ksort($buckets);

        $totals = [
            'total_payment_requests' => 0,
            'confirmed_payment_count' => 0,
            'confirmed_collection_cents' => 0,
            'pending_request_count' => 0,
            'pending_request_cents' => 0,
            'rejected_request_count' => 0,
            'rejected_request_cents' => 0,
            'confirmed_remittance_count' => 0,
            'confirmed_remittance_cents' => 0,
        ];

        $rows = [];
        foreach ($buckets as $b) {
            $totals['total_payment_requests'] += $b['total_payment_requests'];
            $totals['confirmed_payment_count'] += $b['confirmed_payment_count'];
            $totals['confirmed_collection_cents'] += $b['confirmed_collection_cents'];
            $totals['pending_request_count'] += $b['pending_request_count'];
            $totals['pending_request_cents'] += $b['pending_request_cents'];
            $totals['rejected_request_count'] += $b['rejected_request_count'];
            $totals['rejected_request_cents'] += $b['rejected_request_cents'];
            $totals['confirmed_remittance_count'] += $b['confirmed_remittance_count'];
            $totals['confirmed_remittance_cents'] += $b['confirmed_remittance_cents'];

            $rows[] = [
                'period' => $b['period'],
                'date' => $b['date'],
                'start_date' => $b['start_date'],
                'end_date' => $b['end_date'],
                'total_payment_requests' => $b['total_payment_requests'],
                'confirmed_payment_count' => $b['confirmed_payment_count'],
                'confirmed_collection_amount' => TeacherSettlementService::money($b['confirmed_collection_cents']),
                'pending_request_count' => $b['pending_request_count'],
                'pending_request_amount' => TeacherSettlementService::money($b['pending_request_cents']),
                'rejected_request_count' => $b['rejected_request_count'],
                'rejected_request_amount' => TeacherSettlementService::money($b['rejected_request_cents']),
                'confirmed_remittance_count' => $b['confirmed_remittance_count'],
                'confirmed_remittance_amount' => TeacherSettlementService::money($b['confirmed_remittance_cents']),
                'outstanding_balance' => TeacherSettlementService::money($b['confirmed_collection_cents'] - $b['confirmed_remittance_cents']),
            ];
        }

        return [
            'currency' => 'PKR',
            'group_by' => $groupBy,
            'from' => $filters['from'] ?? null,
            'to' => $filters['to'] ?? null,
            'rows' => $rows,
            'totals' => [
                'total_payment_requests' => $totals['total_payment_requests'],
                'confirmed_payment_count' => $totals['confirmed_payment_count'],
                'confirmed_collection_amount' => TeacherSettlementService::money($totals['confirmed_collection_cents']),
                'pending_request_count' => $totals['pending_request_count'],
                'pending_request_amount' => TeacherSettlementService::money($totals['pending_request_cents']),
                'rejected_request_count' => $totals['rejected_request_count'],
                'rejected_request_amount' => TeacherSettlementService::money($totals['rejected_request_cents']),
                'confirmed_remittance_count' => $totals['confirmed_remittance_count'],
                'confirmed_remittance_amount' => TeacherSettlementService::money($totals['confirmed_remittance_cents']),
                'outstanding_balance' => TeacherSettlementService::money($totals['confirmed_collection_cents'] - $totals['confirmed_remittance_cents']),
            ],
        ];
    }

    private function resolvePeriodBucket(string $dateStr, string $groupBy): array
    {
        $date = Carbon::parse($dateStr);
        return match ($groupBy) {
            'week' => [
                $date->format('o-\WW'),
                $date->copy()->startOfWeek(Carbon::MONDAY)->toDateString(),
                $date->copy()->endOfWeek(Carbon::SUNDAY)->toDateString(),
            ],
            'month' => [
                $date->format('Y-m'),
                $date->copy()->startOfMonth()->toDateString(),
                $date->copy()->endOfMonth()->toDateString(),
            ],
            default => [
                $date->toDateString(),
                $date->toDateString(),
                $date->toDateString(),
            ],
        };
    }
}
