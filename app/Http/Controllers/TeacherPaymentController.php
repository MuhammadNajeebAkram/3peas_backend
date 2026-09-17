<?php

namespace App\Http\Controllers;

use App\Models\OfferedProgram;
use App\Models\SubscriptionPaymentRequest;
use App\Models\TeacherProfile;
use App\Models\UserSubscription;
use App\Models\WebUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TeacherPaymentController extends Controller
{
    public function methods()
    {
        return response()->json(['methods' => [
            ['key' => 'bank', 'label' => 'Bank', 'requires_account' => true],
            ['key' => 'jazzcash', 'label' => 'JazzCash', 'requires_account' => true],
            ['key' => 'teacher', 'label' => 'Pay through teacher', 'requires_collection_code' => true,
                'available' => $this->teacherQuery()->exists()],
        ]]);
    }

    private function teacherQuery()
    {
        return TeacherProfile::where('status', 'active')->whereNotNull('collection_code')
            ->where('collection_code', '!=', '')
            ->whereHas('webUser', fn ($q) => $q->where('role', 'teacher')->where('status', 'active'));
    }

    private function teacher(bool $lock = false): TeacherProfile
    {
        abort_unless(auth('web_api')->user()?->role === 'teacher', 403);
        $query = $this->teacherQuery()->where('web_user_id', auth('web_api')->id());
        $teacher = ($lock ? $query->lockForUpdate() : $query)->first();
        abort_unless($teacher, 403, 'Payment collection is not enabled for this teacher.');
        return $teacher;
    }

    public function lookup(Request $request)
    {
        $data = $request->validate(['collection_code' => 'required|string|max:32']);
        $teacher = $this->teacherQuery()->where('collection_code', $data['collection_code'])->first();
        abort_unless($teacher, 422, 'Invalid or inactive collection code.');
        return response()->json(['teacher' => ['name' => $teacher->webUser->name]]);
    }

    public function store(Request $request)
    {
        abort_unless(auth('web_api')->user()?->role === 'student', 403);
        $data = $request->validate([
            'offered_program_id' => 'required|integer|exists:offered_programs,id',
            'collection_code' => 'required|string|max:32',
            'payment_account_id' => 'prohibited', 'transaction_id' => 'prohibited', 'proof_file' => 'prohibited',
        ]);
        $payment = DB::transaction(function () use ($data) {
            $teacher = $this->teacherQuery()->where('collection_code', $data['collection_code'])->lockForUpdate()->first();
            abort_unless($teacher, 422, 'Invalid or inactive collection code.');
            $student = WebUser::whereKey(auth('web_api')->id())->lockForUpdate()->firstOrFail();
            abort_unless($student->status === 'active', 403);
            $program = OfferedProgram::with('offeredClass')->findOrFail($data['offered_program_id']);
            $class = $program->offeredClass;
            abort_unless($program->is_active && $class?->is_active, 422, 'This program is unavailable.');
            abort_if($class->session_end && $class->session_end < today()->toDateString(), 422, 'This program has ended.');
            $price = $class->price ?? $class->Price;
            $amount = $class->discount_price ?? $price;
            abort_if($class->is_free || !is_numeric($price) || !is_numeric($amount) || $amount <= 0 || $amount > $price, 422, 'This program does not have a valid paid price.');
            abort_if(SubscriptionPaymentRequest::where('user_id', $student->id)->where('offered_program_id', $program->id)->where('status', 'pending')->exists(), 409, 'A payment request is already pending for this program.');
            abort_if(UserSubscription::where('user_id', $student->id)->where('offered_program_id', $program->id)->where('status', 'active')->where(fn ($q) => $q->whereNull('expires_at')->orWhereDate('expires_at', '>=', today()))->exists(), 409, 'You already have an active subscription.');
            return SubscriptionPaymentRequest::create([
                'user_id' => $student->id, 'offered_program_id' => $program->id,
                'payment_method' => 'teacher', 'teacher_profile_id' => $teacher->id,
                'collection_code_snapshot' => $teacher->collection_code,
                'price' => $price, 'final_amount' => $amount, 'discount_amount' => $price - $amount,
                'discount_percentage' => round(($price - $amount) / $price * 100, 2), 'status' => 'pending',
            ]);
        });
        return response()->json(['message' => 'Awaiting teacher confirmation.', 'payment_request' => $payment], 201);
    }

    public function index(Request $request)
    {
        $teacher = app(TeacherAccountController::class)->profile();
        $data = $request->validate(['status' => 'sometimes|in:pending,approved,rejected']);
        $query = SubscriptionPaymentRequest::where('payment_method', 'teacher')->where('teacher_profile_id', $teacher->id)
            ->select(['id', 'user_id', 'offered_program_id', 'final_amount', 'status', 'created_at', 'confirmed_at', 'receipt_number', 'rejection_reason'])
            ->with(['user:id,name', 'offeredProgram:id,title']);
        if (isset($data['status'])) {
            $query->where('status', $data['status']);
        }
        return response()->json($query->latest('id')->paginate(20));
    }

    public function mine()
    {
        return response()->json(SubscriptionPaymentRequest::where('user_id', auth('web_api')->id())
            ->where('payment_method', 'teacher')
            ->select(['id', 'offered_program_id', 'final_amount', 'status', 'created_at', 'confirmed_at', 'receipt_number', 'rejection_reason', 'subscription_id'])
            ->with('offeredProgram:id,title')->latest('id')->paginate(20));
    }

    public function approve(Request $request, int $id)
    {
        $request->validate(['received_full_payment' => 'required|accepted']);
        $payment = DB::transaction(function () use ($id) {
            $teacher = $this->teacher(true);
            $payment = SubscriptionPaymentRequest::where('teacher_profile_id', $teacher->id)->where('payment_method', 'teacher')->lockForUpdate()->findOrFail($id);
            if ($payment->status === 'approved') {
                return $payment;
            }
            abort_unless($payment->status === 'pending', 409, 'Only pending payments can be approved.');
            $student = WebUser::whereKey($payment->user_id)->lockForUpdate()->firstOrFail();
            abort_unless($student->status === 'active', 409, 'The student account is inactive.');
            $program = OfferedProgram::with('offeredClass')->findOrFail($payment->offered_program_id);
            $class = $program->offeredClass;
            abort_unless($program->is_active && $class?->is_active, 409, 'This program is unavailable.');
            abort_if($class->session_end && $class->session_end < today()->toDateString(), 409, 'This program has ended.');
            $subscription = UserSubscription::firstOrNew(['user_id' => $payment->user_id, 'offered_program_id' => $payment->offered_program_id]);
            abort_if($subscription->status === 'active' && (!$subscription->expires_at || $subscription->expires_at->endOfDay()->isFuture()), 409, 'The student already has an active subscription.');
            $subscription->fill(['status' => 'active', 'access_type' => $payment->discount_amount > 0 ? 'discounted' : 'paid',
                'price_paid' => $payment->final_amount, 'started_at' => today(), 'expires_at' => $class->session_end,
                'approved_at' => now(), 'approved_by' => null])->save();
            $payment->update(['status' => 'approved', 'subscription_id' => $subscription->id,
                'approved_at' => now(), 'confirmed_by_web_user_id' => $teacher->web_user_id, 'confirmed_at' => now(),
                'receipt_number' => 'TCR-'.Str::ulid()]);
            return $payment;
        });
        return response()->json(['message' => 'Payment confirmed and subscription activated.', 'payment_request' => $payment]);
    }

    public function reject(Request $request, int $id)
    {
        $data = $request->validate(['rejection_reason' => 'required|string|max:255']);
        $payment = DB::transaction(function () use ($id, $data) {
            $teacher = $this->teacher(true);
            $payment = SubscriptionPaymentRequest::where('teacher_profile_id', $teacher->id)->where('payment_method', 'teacher')->lockForUpdate()->findOrFail($id);
            abort_unless($payment->status === 'pending', 409, 'Only pending payments can be rejected.');
            $payment->update(['status' => 'rejected', 'rejected_by_web_user_id' => $teacher->web_user_id, 'rejection_reason' => $data['rejection_reason']]);
            return $payment;
        });
        return response()->json(['payment_request' => $payment]);
    }
}
