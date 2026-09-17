<?php

namespace App\Http\Controllers;

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
        $query = TeacherProfile::with(['webUser:id,name,email,phone,status', 'city:id,name', 'institute:id,name']);
        if (isset($data['status'])) $query->where('status', $data['status']);
        if (isset($data['search'])) {
            $query->whereHas('webUser', fn ($q) => $q->where(fn ($q) => $q->where('name', 'like', '%'.$data['search'].'%')->orWhere('email', 'like', '%'.$data['search'].'%')));
        }
        return response()->json($query->latest('id')->paginate(20));
    }

    public function show(int $id, TeacherSettlementService $settlements)
    {
        $teacher = TeacherProfile::with(['webUser:id,name,email,phone,status', 'city:id,name', 'institute:id,name'])->findOrFail($id);
        return response()->json(['teacher' => $teacher->makeVisible('admin_note'), 'summary' => $settlements->summary($id),
            'events' => DB::table('teacher_profile_events')->where('teacher_profile_id', $id)->orderByDesc('id')->get()]);
    }

    public function status(Request $request, int $id)
    {
        $data = $request->validate(['status' => 'required|in:active,rejected,suspended',
            'admin_note' => 'required_if:status,rejected,suspended|nullable|string|max:1000',
            'collection_code' => ['nullable', 'string', 'max:32', 'regex:/^[A-Z0-9-]{6,32}$/', Rule::unique('teacher_profiles', 'collection_code')->ignore($id)],
        ]);
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
            return $teacher;
        });
        return response()->json(['teacher' => $teacher->makeVisible('admin_note')]);
    }

    public function code(Request $request, int $id)
    {
        $data = $request->validate(['collection_code' => ['nullable', 'string', 'max:32', 'regex:/^[A-Z0-9-]{6,32}$/', Rule::unique('teacher_profiles', 'collection_code')->ignore($id)]]);
        $teacher = DB::transaction(function () use ($id, $data) {
            $teacher = TeacherProfile::lockForUpdate()->findOrFail($id);
            abort_unless($teacher->status === 'active', 409, 'Activate the teacher before assigning a collection code.');
            $this->assignCode($teacher, $data['collection_code'] ?? $this->newCode());
            $teacher->save();
            return $teacher;
        });
        return response()->json(['teacher' => $teacher]);
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
}
