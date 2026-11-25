<?php

namespace App\Http\Controllers;

use App\Http\Services\ReceiptService;
use App\Http\Services\StudentSubscriptionPaymentService;
use App\Jobs\SendPaymentReceiptEmailJob;
use App\Models\SubscriptionPaymentRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class AdminStudentPaymentRequestController extends Controller
{
    public function index(Request $request, StudentSubscriptionPaymentService $payments)
    {
        $filters = $request->validate([
            'status' => 'sometimes|in:pending,approved,rejected', 'student' => 'sometimes|string|max:120',
            'teacher' => 'sometimes|string|max:120', 'teacher_profile_id' => 'sometimes|integer|exists:teacher_profiles,id',
            'collection_code' => 'sometimes|string|max:32',
            'offered_program_id' => 'sometimes|integer', 'payment_method' => 'sometimes|string|max:20',
            'reference' => 'sometimes|string|max:255', 'date_from' => 'sometimes|date', 'date_to' => 'sometimes|date|after_or_equal:date_from',
        ]);
        return response()->json($payments->adminQuery($filters)->paginate(min(100, max(1, (int) $request->query('per_page', 20)))));
    }

    public function show(int $id)
    {
        $payment = SubscriptionPaymentRequest::with([
            'user:id,name,email,phone', 'teacherProfile.webUser:id,name,email,phone', 'offeredProgram.offeredClass.userClass',
            'paymentAccount', 'userSubscription', 'userApproved:id,name', 'userRejected:id,name',
            'receipt', 'financialTransaction.entries.account',
        ])->findOrFail($id);
        $payment->review_history = DB::table('subscription_payment_request_events as events')
            ->leftJoin('users', function ($join) { $join->on('users.id', '=', 'events.reviewer_id')->where('events.reviewer_type', '=', 'admin'); })
            ->leftJoin('web_users', function ($join) { $join->on('web_users.id', '=', 'events.reviewer_id')->whereIn('events.reviewer_type', ['teacher', 'student']); })
            ->where('events.subscription_payment_request_id', $id)
            ->orderBy('events.id')->get(['events.*', 'users.name as admin_name', 'web_users.name as web_user_name']);
        $payment->proof_endpoint = $payment->proof_file_path ? url('/api/admin/auth/student-payment-requests/'.$id.'/proof') : null;
        return response()->json(['payment_request' => $payment]);
    }

    public function approve(Request $request, int $id, StudentSubscriptionPaymentService $payments)
    {
        $data = $request->validate(['admin_remarks' => 'nullable|string|max:255', 'subscription_payment_request_id' => 'prohibited', 'access_type' => 'prohibited', 'started_at' => 'prohibited', 'expires_at' => 'prohibited']);
        return response()->json(['message' => 'Payment approved and subscription activated.', 'payment_request' => $payments->approve($id, 'admin', (int) auth('api')->id(), null, $data['admin_remarks'] ?? null)]);
    }

    public function reject(Request $request, int $id, StudentSubscriptionPaymentService $payments)
    {
        $data = $request->validate(['rejection_reason' => 'required|string|min:3|max:255', 'admin_remarks' => 'nullable|string|max:255']);
        return response()->json(['message' => 'Payment request rejected.', 'payment_request' => $payments->reject($id, 'admin', (int) auth('api')->id(), $data['rejection_reason'], null, $data['admin_remarks'] ?? null)]);
    }

    public function receipt(int $id, ReceiptService $receiptService)
    {
        $payment = SubscriptionPaymentRequest::findOrFail($id);
        abort_unless($payment->status === 'approved', 404, 'Receipt is only available for approved payments.');

        $receipt = $payment->receipt ?: $receiptService->issueReceipt($payment);

        if ($receipt->pdf_path && Storage::disk('s3')->exists($receipt->pdf_path)) {
            return response(Storage::disk('s3')->get($receipt->pdf_path))
                ->header('Content-Type', 'application/pdf')
                ->header('Content-Disposition', "attachment; filename=\"Receipt-{$receipt->receipt_number}.pdf\"")
                ->header('Cache-Control', 'private, no-store, no-cache, max-age=0');
        }

        $pdf = $receiptService->getPdf($receipt);
        return $pdf->download("Receipt-{$receipt->receipt_number}.pdf")
            ->header('Cache-Control', 'private, no-store, no-cache, max-age=0');
    }

    public function resendReceipt(int $id, ReceiptService $receiptService)
    {
        $payment = SubscriptionPaymentRequest::findOrFail($id);
        abort_unless($payment->status === 'approved', 409, 'Receipt can only be emailed for approved payments.');

        $receipt = $payment->receipt ?: $receiptService->issueReceipt($payment);

        SendPaymentReceiptEmailJob::dispatch($receipt->id)->afterCommit();

        return response()->json([
            'message' => 'Payment receipt has been queued for email delivery to ' . ($receipt->email_sent_to ?: $payment->user?->email),
            'receipt' => $receipt->fresh(),
        ]);
    }
}
