<?php

namespace App\Http\Controllers;

use App\Http\Services\TeacherSettlementService;
use App\Models\TeacherProfile;
use App\Models\TeacherSettlement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class TeacherSettlementController extends Controller
{
    public function __construct(private TeacherSettlementService $settlements) {}

    private function teacher(): TeacherProfile
    {
        // Suspended teachers may remit existing collections, but cannot collect new money.
        return app(TeacherAccountController::class)->profile();
    }

    public function index(Request $request)
    {
        return $this->listing($request, $this->teacher()->id);
    }

    public function adminIndex(Request $request)
    {
        $data = $request->validate(['teacher_profile_id' => 'sometimes|integer|exists:teacher_profiles,id']);
        return $this->listing($request, $data['teacher_profile_id'] ?? null);
    }

    private function listing(Request $request, ?int $teacherId)
    {
        $data = $request->validate(['status' => 'sometimes|in:pending,confirmed,rejected']);
        $query = TeacherSettlement::with('allocations');
        if ($teacherId !== null) $query->where('teacher_profile_id', $teacherId);
        if (isset($data['status'])) $query->where('status', $data['status']);
        return response()->json($query->latest('id')->paginate(20));
    }

    public function collections()
    {
        return response()->json($this->settlements->collections($this->teacher()->id)->latest('id')->paginate(20));
    }

    public function adminCollections(int $id)
    {
        TeacherProfile::findOrFail($id);
        return response()->json($this->settlements->collections($id)->latest('id')->paginate(20));
    }

    public function store(Request $request)
    {
        $teacher = $this->teacher();
        $data = $request->validate([
            'submission_key' => 'required|uuid', 'payment_method' => 'required|in:cash,bank,jazzcash',
            'payment_account_id' => 'required_unless:payment_method,cash|prohibited_if:payment_method,cash|nullable|integer|exists:payment_accounts,id',
            'transaction_reference' => 'required_unless:payment_method,cash|prohibited_if:payment_method,cash|nullable|string|max:100',
            'paid_at' => 'required|date_format:Y-m-d|before_or_equal:today', 'notes' => 'nullable|string|max:1000',
            'proof_file' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:2048',
            'allocations' => 'required|array|min:1|max:100',
            'allocations.*' => 'required|array:payment_request_id,amount',
            'allocations.*.payment_request_id' => 'required|integer|distinct',
            'allocations.*.amount' => ['required', 'numeric', 'min:0.01', 'max:9999999999.99', 'regex:/^\d+(\.\d{1,2})?$/'],
        ]);
        return response()->json(['settlement' => $this->settlements->submit($teacher->id, $data, $request->file('proof_file'))], 201);
    }

    public function confirm(Request $request, int $id)
    {
        $request->validate(['received_funds' => 'required|accepted']);
        return response()->json(['settlement' => $this->settlements->review($id, auth('api')->id(), 'confirmed', null)]);
    }

    public function reject(Request $request, int $id)
    {
        $data = $request->validate(['rejection_reason' => 'required|string|max:1000']);
        return response()->json(['settlement' => $this->settlements->review($id, auth('api')->id(), 'rejected', $data['rejection_reason'])]);
    }

    public function proof(int $id)
    {
        $settlement = TeacherSettlement::where('teacher_profile_id', $this->teacher()->id)->findOrFail($id);
        return $this->download($settlement);
    }

    public function adminProof(int $id)
    {
        return $this->download(TeacherSettlement::findOrFail($id));
    }

    private function download(TeacherSettlement $settlement)
    {
        abort_unless($settlement->proof_path && Storage::disk('local')->exists($settlement->proof_path), 404);
        return Storage::disk('local')->download($settlement->proof_path, basename($settlement->proof_path), ['X-Content-Type-Options' => 'nosniff']);
    }
}
