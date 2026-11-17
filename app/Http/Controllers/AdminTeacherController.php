<?php

namespace App\Http\Controllers;

use App\Http\Services\TeacherFinancialReportService;
use App\Http\Services\TeacherSettlementService;
use App\Models\TeacherProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AdminTeacherController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate(['status' => 'sometimes|in:pending,active,rejected,suspended', 'search' => 'sometimes|string|max:100']);

        // Auto-provision teacher profiles for any teacher web users that lack one
        \App\Models\WebUser::where('role', 'teacher')->whereNull('deleted_at')
            ->whereNotIn('id', TeacherProfile::select('web_user_id'))
            ->each(function ($user) {
                TeacherProfile::create(['web_user_id' => $user->id]);
            });

        $query = TeacherProfile::with(['webUser:id,name,email,phone,status', 'city:id,name', 'institute:id,name']);
        if (isset($data['status'])) $query->where('status', $data['status']);
        if (isset($data['search'])) {
            $query->whereHas('webUser', fn ($q) => $q->where(fn ($q) => $q->where('name', 'like', '%'.$data['search'].'%')
                ->orWhere('email', 'like', '%'.$data['search'].'%')
                ->orWhere('phone', 'like', '%'.$data['search'].'%')));
        }
        $teachers = $query->latest('id')->paginate(20);
        $teachers->getCollection()->each(fn (TeacherProfile $teacher) => $teacher->makeHidden('collection_code'));
        return response()->json($teachers);
    }

    public function show(int $id, TeacherSettlementService $settlements)
    {
        $teacher = TeacherProfile::with(['webUser:id,name,email,phone,status', 'city:id,name', 'institute:id,name'])->findOrFail($id);
        $events = DB::table('teacher_profile_events')->where('teacher_profile_id', $id)->orderByDesc('id')->get();
        if (!$this->mayViewCollectionPermission()) {
            $events->each(function ($event) {
                unset($event->collection_code);
                if ($event->details) {
                    $details = is_string($event->details) ? json_decode($event->details, true) : (array) $event->details;
                    unset($details['previous_code'], $details['collection_code']);
                    $event->details = $details;
                }
            });
        }
        $summary = $settlements->summary($id);
        return response()->json(['teacher' => $teacher->makeHidden('collection_code')->makeVisible('admin_note'), 'summary' => $summary,
            'financial_summary' => $summary,
            'events' => $events]);
    }

    public function payments(Request $request, int $id, TeacherFinancialReportService $reports)
    {
        TeacherProfile::findOrFail($id);
        $filters = $request->validate([
            'from' => 'nullable|date_format:Y-m-d',
            'to' => 'nullable|date_format:Y-m-d|after_or_equal:from',
            'status' => 'nullable|in:pending,approved,rejected',
            'payment_method' => 'nullable|in:teacher,account,cash,bank,jazzcash',
            'offered_program_id' => 'nullable|integer|min:1',
            'receipt_number' => 'nullable|string|max:100',
            'reference' => 'nullable|string|max:100',
            'search' => 'nullable|string|max:120',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);
        if (empty($filters['receipt_number']) && !empty($filters['reference'])) {
            $filters['receipt_number'] = $filters['reference'];
        }

        $perPage = (int) ($filters['per_page'] ?? 20);
        $paginator = $reports->payments($id, $filters, $perPage);
        $paginator->getCollection()->transform(function (array $row) use ($id) {
            $row['proof_endpoint'] = !empty($row['has_proof'])
                ? url("/api/admin/auth/teachers/{$id}/payments/{$row['id']}/proof")
                : null;
            return $row;
        });

        return response()->json([
            'summary' => $reports->summary($id, $filters),
            'payments' => $paginator,
        ]);
    }

    public function paymentDetail(int $id, int $paymentId, TeacherFinancialReportService $reports)
    {
        TeacherProfile::findOrFail($id);
        $detail = $reports->paymentDetail($id, $paymentId);
        $detail['proof_endpoint'] = !empty($detail['has_proof'])
            ? url("/api/admin/auth/teachers/{$id}/payments/{$paymentId}/proof")
            : null;

        return response()->json([
            'payment_request' => $detail,
        ]);
    }

    public function settlements(Request $request, int $id, TeacherFinancialReportService $reports)
    {
        TeacherProfile::findOrFail($id);
        $filters = $request->validate([
            'from' => 'nullable|date_format:Y-m-d',
            'to' => 'nullable|date_format:Y-m-d|after_or_equal:from',
            'status' => 'nullable|in:pending,confirmed,rejected',
            'payment_method' => 'nullable|in:cash,bank,jazzcash',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $perPage = (int) ($filters['per_page'] ?? 20);
        $paginator = $reports->settlements($id, $filters, $perPage);
        $paginator->getCollection()->transform(function (array $row) use ($id) {
            $row['proof_endpoint'] = !empty($row['has_proof'])
                ? url("/api/admin/auth/teachers/{$id}/settlements/{$row['id']}/proof")
                : null;
            return $row;
        });

        return response()->json([
            'settlement_summary' => $reports->settlementSummary($id, $filters),
            'settlements' => $paginator,
        ]);
    }

    public function activate(Request $request, ?int $id = null)
    {
        $id = $id ?? $request->input('id') ?? $request->input('teacher_id') ?? $request->input('teacher_profile_id');
        abort_unless($id, 422, 'Teacher ID is required.');

        if (!$request->has('status')) {
            $isActive = $request->boolean('activate', true) && $request->boolean('is_active', true);
            $request->merge(['status' => $isActive ? 'active' : 'suspended']);
        }

        return $this->status($request, (int) $id);
    }

    public function status(Request $request, int $id)
    {
        $data = $request->validate(['status' => 'required|in:active,rejected,suspended',
            'admin_note' => 'required_if:status,rejected,suspended|nullable|string|max:1000',
            'collection_code' => ['nullable', 'string', 'max:32', 'regex:/^[A-Z0-9-]{6,32}$/', Rule::unique('teacher_profiles', 'collection_code')->ignore($id)],
        ]);
        if (!empty($data['collection_code'])) {
            abort_unless($this->mayManageCollectionPermission(), 403, 'Collection permission management is required to assign a collection code.');
        }
        $teacher = DB::transaction(function () use ($id, $data) {
            $teacher = TeacherProfile::lockForUpdate()->findOrFail($id);
            if ($data['status'] === 'active') {
                abort_unless($teacher->webUser && $teacher->webUser->role === 'teacher' && $teacher->webUser->status === 'active' && filled($teacher->webUser->phone), 422, 'An active teacher account with a contact phone is required.');
                $code = $data['collection_code'] ?? $teacher->collection_code ?? $this->newCode();
                $this->assignCode($teacher, $code);
                if (!$teacher->approved_at) {
                    $teacher->approved_by = auth('api')->id();
                    $teacher->approved_at = now();
                }
            } else {
                abort_if(isset($data['collection_code']), 422, 'Codes can only be assigned to active teachers.');
            }
            $before = $teacher->status;
            $teacher->forceFill(['status' => $data['status'], 'admin_note' => $data['admin_note'] ?? null,
                'status_changed_by' => auth('api')->id(), 'status_changed_at' => now()])->save();
            $this->event($teacher, 'status_changed', ['from' => $before, 'to' => $teacher->status, 'note' => $data['admin_note'] ?? null]);
            if ($data['status'] === 'active') {
                app(\App\Http\Services\TeacherSubscriptionService::class)
                    ->assignDefaultFree($teacher, auth('api')->id());
            }
            return $teacher;
        });
        return response()->json(['teacher' => $teacher->makeHidden('collection_code')->makeVisible('admin_note')]);
    }

    public function code(Request $request, int $id)
    {
        abort_unless($this->mayManageCollectionPermission(), 403, 'Collection permission management is required to change a collection code.');
        $data = $request->validate(['collection_code' => ['nullable', 'string', 'max:32', 'regex:/^[A-Z0-9-]{6,32}$/', Rule::unique('teacher_profiles', 'collection_code')->ignore($id)]]);
        $teacher = DB::transaction(function () use ($id, $data) {
            $teacher = TeacherProfile::lockForUpdate()->findOrFail($id);
            abort_unless($teacher->status === 'active', 409, 'Activate the teacher before assigning a collection code.');
            $this->assignCode($teacher, $data['collection_code'] ?? $this->newCode());
            $teacher->save();
            return $teacher;
        });
        return response()->json(['teacher' => $teacher->makeHidden('collection_code')]);
    }

    private function newCode(): string
    {
        do { $code = 'COL-'.Str::upper(Str::random(10)); }
        while (DB::table('teacher_profile_events')->where('collection_code', $code)->exists()
            || TeacherProfile::where('collection_code', $code)->exists());
        return $code;
    }

    private function assignCode(TeacherProfile $teacher, string $code): void
    {
        if ($teacher->collection_code === $code) return;
        abort_if(DB::table('teacher_profile_events')->where('collection_code', $code)->exists()
            || TeacherProfile::where('collection_code', $code)->where('id', '!=', $teacher->id)->exists(), 422, 'This collection code has already been used.');
        DB::table('teacher_profile_events')->insert(['teacher_profile_id' => $teacher->id, 'admin_id' => auth('api')->id(),
            'action' => 'code_assigned', 'collection_code' => $code,
            'details' => json_encode(['previous_code' => $teacher->collection_code]), 'created_at' => now()]);
        $teacher->collection_code = $code;
    }

    private function event(TeacherProfile $teacher, string $action, array $details): void
    {
        DB::table('teacher_profile_events')->insert(['teacher_profile_id' => $teacher->id, 'admin_id' => auth('api')->id(),
            'action' => $action, 'details' => json_encode($details), 'created_at' => now()]);
    }

    private function mayManageCollectionPermission(): bool
    {
        return $this->hasPermission('teacher-collection-permissions.manage');
    }

    private function mayViewCollectionPermission(): bool
    {
        return $this->hasPermission('teacher-collection-permissions.view')
            || $this->mayManageCollectionPermission();
    }

    private function hasPermission(string $permission): bool
    {
        $user = auth('api')->user();
        if (!$user) return false;
        $user->loadMissing('role.permissions');
        $roleName = str_replace(['-', ' '], '_', strtolower(trim((string) $user->role?->name)));
        return $roleName === 'super_admin'
            || $user->role?->permissions?->contains('name', $permission) === true;
    }
}
