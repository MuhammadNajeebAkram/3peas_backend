<?php

namespace App\Http\Controllers;

use App\Models\OfferedProgram;
use App\Models\SubscriptionPaymentRequest;
use App\Models\TeacherProfile;
use App\Models\UserSubscription;
use App\Models\WebUser;
use App\Http\Services\TeacherCollectionPermissionService;
use App\Http\Services\StudentSubscriptionPaymentService;
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
                'available' => app(TeacherCollectionPermissionService::class)->anyEligibleTeacher()],
        ]]);
    }

    private function teacher(bool $lock = false): TeacherProfile
    {
        abort_unless(auth('web_api')->user()?->role === 'teacher', 403);
        $query = TeacherProfile::with('webUser')->where('web_user_id', auth('web_api')->id());
        $profile = ($lock ? $query->lockForUpdate() : $query)->first();
        return app(TeacherCollectionPermissionService::class)->assertCanCollect($profile);
    }

    public function lookup(Request $request)
    {
        if (is_string($request->input('collection_code'))) {
            $request->merge(['collection_code' => strtoupper(trim($request->input('collection_code')))]);
        }
        $data = $request->validate(['collection_code' => 'required|string|max:32']);
        $teacher = app(TeacherCollectionPermissionService::class)->eligibleTeacherForCode($data['collection_code']);
        abort_unless($teacher, 422, 'Invalid or inactive collection code.');
        return response()->json(['teacher' => ['name' => $teacher->webUser->name]]);
    }

    public function store(Request $request)
    {
        abort_unless(auth('web_api')->user()?->role === 'student', 403);
        if (is_string($request->input('collection_code'))) {
            $request->merge(['collection_code' => strtoupper(trim($request->input('collection_code')))]);
        }
        $data = $request->validate([
            'offered_program_id' => 'required|integer|exists:offered_programs,id',
            'collection_code' => 'required|string|max:32',
            'payment_account_id' => 'prohibited', 'transaction_id' => 'prohibited', 'proof_file' => 'prohibited',
            'amount' => 'prohibited', 'price' => 'prohibited', 'price_paid' => 'prohibited',
            'discount_amount' => 'prohibited', 'discount_percentage' => 'prohibited', 'final_amount' => 'prohibited',
            'access_type' => 'prohibited', 'expires_at' => 'prohibited',
            'user_id' => 'prohibited', 'student_id' => 'prohibited',
            'teacher_profile_id' => 'prohibited', 'approved_by' => 'prohibited', 'subscription_id' => 'prohibited',
        ]);
        $payment = app(StudentSubscriptionPaymentService::class)->submitTeacher(auth('web_api')->id(), $data['offered_program_id'], $data['collection_code']);
        return response()->json(['message' => 'Awaiting teacher confirmation.', 'payment_request' => $payment], 201);
    }

    public function index(Request $request)
    {
        $teacher = app(TeacherAccountController::class)->profile();
        $data = $request->validate(['status' => 'sometimes|in:pending,approved,rejected', 'search' => 'sometimes|string|max:120']);
        $query = SubscriptionPaymentRequest::where('payment_method', 'teacher')->where('teacher_profile_id', $teacher->id)
            ->select(['id', 'user_id', 'offered_program_id', 'payment_account_id', 'final_amount', 'transaction_id', 'payment_method', 'proof_file_path', 'status', 'created_at', 'confirmed_at', 'receipt_number', 'rejection_reason'])
            ->with(['user:id,name', 'offeredProgram:id,title', 'paymentAccount:id,title']);
        if (isset($data['status'])) {
            $query->where('status', $data['status']);
        }
        if (!empty($data['search'])) $query->where(fn ($q) => $q->where('transaction_id', 'like', '%'.$data['search'].'%')->orWhereHas('user', fn ($u) => $u->where('name', 'like', '%'.$data['search'].'%'))->orWhereHas('offeredProgram', fn ($p) => $p->where('title', 'like', '%'.$data['search'].'%')));
        $page = $query->latest('id')->paginate(20);
        $summary = ['pending' => SubscriptionPaymentRequest::where('teacher_profile_id', $teacher->id)->where('payment_method', 'teacher')->where('status', 'pending')->count(),
            'approved' => SubscriptionPaymentRequest::where('teacher_profile_id', $teacher->id)->where('payment_method', 'teacher')->where('status', 'approved')->count(),
            'rejected' => SubscriptionPaymentRequest::where('teacher_profile_id', $teacher->id)->where('payment_method', 'teacher')->where('status', 'rejected')->count()];
        return response()->json(array_merge($page->toArray(), ['summary' => $summary]));
    }

    public function show(int $id)
    {
        $teacher = app(TeacherAccountController::class)->profile();
        $payment = SubscriptionPaymentRequest::where('teacher_profile_id', $teacher->id)->where('payment_method', 'teacher')
            ->with(['user:id,name', 'offeredProgram.offeredClass', 'paymentAccount', 'userSubscription'])
            ->findOrFail($id);
        $payment->review_history = DB::table('subscription_payment_request_events')->where('subscription_payment_request_id', $payment->id)->orderBy('id')->get();
        $payment->can_review = app(TeacherCollectionPermissionService::class)->canCollect($teacher);
        return response()->json(['payment_request' => $payment]);
    }

    public function mine()
    {
        return response()->json(SubscriptionPaymentRequest::where('user_id', auth('web_api')->id())
            ->select(['id', 'offered_program_id', 'final_amount', 'status', 'created_at', 'confirmed_at', 'receipt_number', 'rejection_reason', 'subscription_id'])
            ->with('offeredProgram:id,title')->latest('id')->paginate(20));
    }

    public function approve(Request $request, int $id)
    {
        $request->validate(['received_full_payment' => 'required|accepted']);
        $teacher = $this->teacher();
        $payment = app(StudentSubscriptionPaymentService::class)->approve($id, 'teacher', (int) $teacher->web_user_id, $teacher);
        return response()->json(['message' => 'Payment confirmed and subscription activated.', 'payment_request' => $payment]);
    }

    public function reject(Request $request, int $id)
    {
        $data = $request->validate(['rejection_reason' => 'required|string|max:255']);
        $teacher = $this->teacher();
        $payment = app(StudentSubscriptionPaymentService::class)->reject($id, 'teacher', (int) $teacher->web_user_id, $data['rejection_reason'], $teacher);
        return response()->json(['payment_request' => $payment]);
    }
}
