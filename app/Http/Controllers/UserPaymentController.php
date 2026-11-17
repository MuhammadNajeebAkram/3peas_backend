<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Models\SubscriptionPaymentRequest;
use App\Http\Services\AwsUploadService;
use App\Http\Services\StudentSubscriptionPaymentService;
use Illuminate\Support\Facades\Storage;

class UserPaymentController extends Controller
{
    protected $awsUploadService;

    public function __construct()
    {
        $this->awsUploadService = new AwsUploadService();
    }

    public function submitPaymentRequest(Request $request){
        if ($request->input('payment_method') === 'teacher') {
            return app(TeacherPaymentController::class)->store($request);
        }
        abort_unless(auth('web_api')->user()?->role === 'student' && auth('web_api')->user()?->status === 'active', 403);
        foreach (['transaction_id', 'payer_name', 'payer_phone'] as $field) {
            if (is_string($request->input($field))) {
                $request->merge([$field => trim($request->input($field))]);
            }
        }
        $request->validate(['payment_method' => 'sometimes|in:account,bank,jazzcash']);
        $userId = auth('web_api')->id();
        $data = $request->validate([
            'offered_program_id' => 'required|integer|exists:offered_programs,id',
            'payment_account_id' => 'required|integer|exists:payment_accounts,id',
            'transaction_id' => 'required|string|max:255|unique:subscription_payment_requests,transaction_id',
            'payer_name' => 'required|string|max:255',
            'payer_phone' => 'required|string|max:20',
            'proof_file' => 'required|file|mimes:jpg,jpeg,png,pdf|max:5120',
            'amount' => 'prohibited', 'price' => 'prohibited', 'price_paid' => 'prohibited',
            'discount_amount' => 'prohibited', 'discount_percentage' => 'prohibited', 'final_amount' => 'prohibited',
            'access_type' => 'prohibited', 'expires_at' => 'prohibited',
            'user_id' => 'prohibited', 'student_id' => 'prohibited', 'teacher_profile_id' => 'prohibited',
            'approved_by' => 'prohibited', 'subscription_id' => 'prohibited',
        ]);

        $file = $request->file('proof_file');
        $extension = strtolower($file->getClientOriginalExtension());
        $path = $this->awsUploadService->uploadFileToS3($file, $extension,
            'payment_proofs/'.date('Y').'/'.date('m'), 'payment-slip-'.$userId.'-'.\Illuminate\Support\Str::uuid().'.'.$extension);
        abort_unless($path, 503, 'Payment proof upload failed. Please try again.');
        try {
            $payment = app(StudentSubscriptionPaymentService::class)->submitAccount($userId, $data, $path);
        } catch (\Throwable $e) {
            Storage::disk('s3')->delete($path);
            throw $e;
        }
        return response()->json(['message' => 'Payment request submitted and is awaiting review.', 'payment_request' => $payment], 201);
    }

    public function offerings() { return response()->json(['data' => app(StudentSubscriptionPaymentService::class)->offerings()]); }

    public function methods() { return response()->json(['methods' => app(StudentSubscriptionPaymentService::class)->paymentAccounts(), 'teacher_collection_available' => app(\App\Http\Services\TeacherCollectionPermissionService::class)->anyEligibleTeacher()]); }

    public function mine() { return response()->json(SubscriptionPaymentRequest::where('user_id', auth('web_api')->id())->with(['offeredProgram:id,title', 'userSubscription:id,status,expires_at', 'paymentAccount:id,title'])->latest('id')->paginate(20)); }

    public function proof(int $id)
    {
        $payment = SubscriptionPaymentRequest::findOrFail($id);
        abort_unless((int) $payment->user_id === (int) auth('web_api')->id(), 404);
        abort_unless($payment->proof_file_path, 404);
        abort_unless(Storage::disk('s3')->exists($payment->proof_file_path), 404);
        $extension = strtolower(pathinfo($payment->proof_file_path, PATHINFO_EXTENSION));
        $type = match ($extension) { 'jpg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'pdf' => 'application/pdf', default => 'application/octet-stream' };
        return response(Storage::disk('s3')->get($payment->proof_file_path))->header('Content-Type', $type)->header('Cache-Control', 'private, no-store');
    }
}
