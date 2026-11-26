<?php

namespace App\Http\Services;

use App\Jobs\SendPaymentReceiptEmailJob;
use App\Models\OfferedClass;
use App\Models\OfferedProgram;
use App\Models\PaymentAccount;
use App\Models\SubscriptionPaymentRequest;
use App\Models\TeacherProfile;
use App\Models\UserSubscription;
use App\Models\WebUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StudentSubscriptionPaymentService
{
    public function offerings(): array
    {
        return OfferedProgram::query()->where('is_active', true)->whereHas('offeredClass', function ($query) {
            $query->where('is_active', true)->where('is_free', false)
                ->where(fn ($q) => $q->whereNull('session_end')->orWhereDate('session_end', '>=', today()));
        })->with('offeredClass')->orderBy('display_order')->get()->map(fn ($program) => [
            'id' => $program->id,
            'slug' => $program->slug,
            'offered_class_id' => $program->offered_class_id,
            'title' => $program->title,
            'program' => $program->title,
            'class' => $program->offeredClass?->userClass?->class_name,
            'session_start' => $program->offeredClass?->session_start,
            'session_end' => $program->offeredClass?->session_end,
            'price' => $this->quoteFor($program->offeredClass)['final_amount'],
            'duration' => $program->offeredClass?->session_start && $program->offeredClass?->session_end
                ? $program->offeredClass->session_start.' – '.$program->offeredClass->session_end : null,
        ])->values()->all();
    }

    public function paymentAccounts()
    {
        return PaymentAccount::query()->where('is_active', true)->whereNot('method', 'cash')
            ->orderBy('display_order')->get(['id', 'title', 'method', 'account_title', 'account_number', 'iban', 'bank_name', 'instructions']);
    }

    public function submitAccount(int $studentId, array $data, ?string $proofPath): SubscriptionPaymentRequest
    {
        return DB::transaction(function () use ($studentId, $data, $proofPath) {
            $student = WebUser::whereKey($studentId)->lockForUpdate()->firstOrFail();
            abort_unless($student->role === 'student' && $student->status === 'active', 403);
            $program = $this->activeProgram((int) $data['offered_program_id']);
            $account = PaymentAccount::whereKey($data['payment_account_id'])->where('is_active', true)->firstOrFail();
            abort_if($account->method === 'cash', 422, 'Cash payment is not supported for online requests.');
            $quote = $this->quoteFor($program->offeredClass);
            abort_if($quote['final_amount'] <= 0, 422, 'This offering has no valid payable price.');
            $this->assertNoOpenOrActiveSubscription($studentId, $program->id);
            $payment = SubscriptionPaymentRequest::create([
                'user_id' => $studentId, 'offered_program_id' => $program->id, 'payment_account_id' => $account->id,
                'payment_method' => 'account', 'price' => $quote['price'], 'discount_amount' => $quote['discount_amount'], 'discount_percentage' => $quote['discount_percentage'],
                'final_amount' => $quote['final_amount'], 'transaction_id' => $data['transaction_id'] ?? null, 'payer_name' => $data['payer_name'] ?? null,
                'payer_phone' => $data['payer_phone'] ?? null, 'proof_file_path' => $proofPath, 'status' => 'pending',
            ]);
            $this->event($payment, 'submitted', 'student', $studentId, null, 'pending');
            return $payment->load(['offeredProgram', 'paymentAccount']);
        });
    }

    public function submitTeacher(int $studentId, int $programId, string $collectionCode): SubscriptionPaymentRequest
    {
        return DB::transaction(function () use ($studentId, $programId, $collectionCode) {
            $student = WebUser::whereKey($studentId)->lockForUpdate()->firstOrFail();
            abort_unless($student->role === 'student' && $student->status === 'active', 403);
            $teacher = app(TeacherCollectionPermissionService::class)->eligibleTeacherForCode($collectionCode, true);
            abort_unless($teacher, 422, 'Invalid or inactive collection code.');
            $program = $this->activeProgram($programId);
            $quote = $this->quoteFor($program->offeredClass);
            abort_if($quote['final_amount'] <= 0, 422, 'This offering has no valid payable price.');
            $this->assertNoOpenOrActiveSubscription($studentId, $programId);
            $payment = SubscriptionPaymentRequest::create([
                'user_id' => $studentId, 'offered_program_id' => $programId, 'payment_method' => 'teacher',
                'teacher_profile_id' => $teacher->id, 'collection_code_snapshot' => $teacher->collection_code,
                'price' => $quote['price'], 'discount_amount' => $quote['discount_amount'], 'discount_percentage' => $quote['discount_percentage'], 'final_amount' => $quote['final_amount'], 'status' => 'pending',
            ]);
            $this->event($payment, 'submitted', 'student', $studentId, null, 'pending', null, ['teacher_profile_id' => $teacher->id]);
            return $payment->load(['offeredProgram', 'teacherProfile.webUser']);
        });
    }

    public function approve(int $requestId, string $reviewerType, int $reviewerId, ?TeacherProfile $teacher = null, ?string $remarks = null): SubscriptionPaymentRequest
    {
        return DB::transaction(function () use ($requestId, $reviewerType, $reviewerId, $teacher, $remarks) {
            $payment = SubscriptionPaymentRequest::whereKey($requestId)->lockForUpdate()->firstOrFail();
            if ($reviewerType === 'teacher') {
                abort_unless($teacher && $payment->payment_method === 'teacher' && (int) $payment->teacher_profile_id === (int) $teacher->id, 404);
            }
            $student = WebUser::whereKey($payment->user_id)->lockForUpdate()->firstOrFail();
            if ($reviewerType === 'teacher') {
                $lockedTeacher = TeacherProfile::with('webUser')->lockForUpdate()->findOrFail($teacher->id);
                app(TeacherCollectionPermissionService::class)->assertCanCollect($lockedTeacher);
                abort_unless($payment->payment_method === 'teacher' && (int) $payment->teacher_profile_id === (int) $lockedTeacher->id, 404);
            }
            if ($payment->status === 'approved') {
                $alreadyByAdmin = $payment->approved_by !== null;
                $alreadyByTeacher = $payment->confirmed_by_web_user_id !== null;
                if (($reviewerType === 'admin' && $alreadyByTeacher) || ($reviewerType === 'teacher' && $alreadyByAdmin)) {
                    abort(409, 'This payment request has already been approved by another reviewer.');
                }
                return $payment->fresh(['offeredProgram', 'userSubscription', 'teacherProfile.webUser', 'user']);
            }
            abort_unless($payment->status === 'pending', 409, 'Only pending payment requests can be approved.');
            abort_unless($student->role === 'student' && $student->status === 'active', 409, 'The student account is inactive.');
            $program = $this->activeProgram((int) $payment->offered_program_id, true);
            $this->validateStoredPrice($payment);
            $subscriptions = UserSubscription::where('user_id', $payment->user_id)
                ->where('offered_program_id', $payment->offered_program_id)
                ->orderByDesc('id')
                ->lockForUpdate()
                ->get();
            $hasActive = $subscriptions->contains(fn (UserSubscription $sub) => $sub->status === 'active' && (!$sub->expires_at || $sub->expires_at->endOfDay()->isFuture()));
            if ($hasActive) {
                throw new HttpResponseException(response()->json(['message' => 'The student already has an active subscription.'], 409));
            }
            $subscription = $subscriptions->first() ?? new UserSubscription(['user_id' => $payment->user_id, 'offered_program_id' => $payment->offered_program_id]);
            $subscription->fill([
                'status' => 'active', 'access_type' => ((float) $payment->discount_amount > 0 ? 'discounted' : 'paid'),
                'price_paid' => $payment->final_amount, 'started_at' => today(), 'expires_at' => $program->offeredClass->session_end,
                'approved_at' => now(), 'approved_by' => $reviewerType === 'admin' ? $reviewerId : null,
            ])->save();

            $previousStatus = $payment->status;
            $payment->update([
                'subscription_id' => $subscription->id, 'status' => 'approved', 'approved_at' => today(),
                'approved_by' => $reviewerType === 'admin' ? $reviewerId : null,
                'confirmed_by_web_user_id' => $reviewerType === 'teacher' ? $reviewerId : null,
                'confirmed_at' => now(),
                'rejected_by' => null, 'rejected_by_web_user_id' => null, 'rejection_reason' => null,
                'admin_remarks' => $remarks ?? $payment->admin_remarks,
            ]);

            // Post double-entry transaction to financial ledger
            $transaction = app(FinancialLedgerService::class)->recordStudentPayment($payment, $reviewerId, $reviewerType);

            // Generate official sequential payment receipt & PDF
            $receipt = app(ReceiptService::class)->issueReceipt($payment, $transaction);

            // Dispatch automated email with receipt PDF after transaction commit
            SendPaymentReceiptEmailJob::dispatch($receipt->id)->afterCommit();

            $this->event($payment, 'approved', $reviewerType, $reviewerId, $previousStatus, 'approved', null, [
                'subscription_id' => $subscription->id,
                'receipt_number' => $receipt->receipt_number,
                'financial_transaction_id' => $transaction->id,
            ]);
            return $payment->fresh(['offeredProgram', 'userSubscription', 'teacherProfile.webUser', 'user', 'receipt', 'financialTransaction']);
        });
    }

    public function reject(int $requestId, string $reviewerType, int $reviewerId, string $reason, ?TeacherProfile $teacher = null, ?string $remarks = null): SubscriptionPaymentRequest
    {
        return DB::transaction(function () use ($requestId, $reviewerType, $reviewerId, $reason, $teacher, $remarks) {
            $payment = SubscriptionPaymentRequest::whereKey($requestId)->lockForUpdate()->firstOrFail();
            if ($reviewerType === 'teacher') {
                $lockedTeacher = TeacherProfile::with('webUser')->lockForUpdate()->findOrFail($teacher?->id);
                app(TeacherCollectionPermissionService::class)->assertCanCollect($lockedTeacher);
                abort_unless($payment->payment_method === 'teacher' && $payment->teacher_profile_id === $lockedTeacher->id, 404);
            }
            abort_unless($payment->status === 'pending', 409, 'Only pending payment requests can be rejected.');
            $previousStatus = $payment->status;
            $payment->update([
                'status' => 'rejected', 'rejection_reason' => $reason,
                'rejected_by_web_user_id' => $reviewerType === 'teacher' ? $reviewerId : null,
                'rejected_by' => $reviewerType === 'admin' ? $reviewerId : null,
                'admin_remarks' => $remarks ?? $payment->admin_remarks,
            ]);
            $this->event($payment, 'rejected', $reviewerType, $reviewerId, $previousStatus, 'rejected', $reason);
            return $payment->fresh(['offeredProgram', 'user', 'teacherProfile.webUser']);
        });
    }

    public function recordExistingRequests(): void
    {
        SubscriptionPaymentRequest::query()->whereNotExists(function ($query) {
            $query->selectRaw('1')->from('subscription_payment_request_events as events')
                ->whereColumn('events.subscription_payment_request_id', 'subscription_payment_requests.id');
        })->orderBy('id')->chunkById(200, function ($payments) {
            foreach ($payments as $payment) {
                $this->event($payment, 'submitted', 'system', 0, null, $payment->status, null, ['legacy' => true]);
            }
        });
    }

    public function event(SubscriptionPaymentRequest $payment, string $type, ?string $reviewerType, ?int $reviewerId, ?string $previous, ?string $next, ?string $reason = null, ?array $details = null): void
    {
        DB::table('subscription_payment_request_events')->insert([
            'subscription_payment_request_id' => $payment->id, 'event_type' => $type,
            'reviewer_type' => $reviewerType, 'reviewer_id' => $reviewerId,
            'previous_status' => $previous, 'new_status' => $next, 'reason' => $reason,
            'details' => $details ? json_encode($details) : null, 'created_at' => now(),
        ]);
    }

    public function adminQuery(array $filters): Builder
    {
        return SubscriptionPaymentRequest::query()->with(['user:id,name,email', 'teacherProfile.webUser:id,name,email', 'offeredProgram.offeredClass.userClass', 'paymentAccount:id,title,method'])
            ->when($filters['status'] ?? null, fn ($q, $value) => $q->where('status', $value))
            ->when($filters['student'] ?? null, fn ($q, $value) => $q->whereHas('user', fn ($u) => $u->where('name', 'like', "%{$value}%")->orWhere('email', 'like', "%{$value}%")))
            ->when($filters['teacher'] ?? null, fn ($q, $value) => $q->whereHas('teacherProfile.webUser', fn ($u) => $u->where('name', 'like', "%{$value}%")))
            ->when($filters['teacher_profile_id'] ?? null, fn ($q, $value) => $q->where('teacher_profile_id', (int) $value))
            ->when($filters['collection_code'] ?? null, fn ($q, $value) => $q->where('collection_code_snapshot', $value))
            ->when($filters['offered_program_id'] ?? null, fn ($q, $value) => $q->where('offered_program_id', $value))
            ->when($filters['payment_method'] ?? null, fn ($q, $value) => $q->where('payment_method', $value))
            ->when($filters['reference'] ?? null, fn ($q, $value) => $q->where(fn ($w) => $w->where('receipt_number', $value)->orWhere('transaction_id', $value)))
            ->when($filters['date_from'] ?? null, fn ($q, $value) => $q->whereDate('created_at', '>=', $value))
            ->when($filters['date_to'] ?? null, fn ($q, $value) => $q->whereDate('created_at', '<=', $value))
            ->latest('id');
    }

    private function activeProgram(int $id, bool $forApproval = false): OfferedProgram
    {
        $program = OfferedProgram::with('offeredClass')->whereKey($id)->firstOrFail();
        $status = $forApproval ? 409 : 422;
        abort_unless($program->is_active && $program->offeredClass?->is_active && !$program->offeredClass?->is_free, $status, 'This program is unavailable.');
        abort_if($program->offeredClass->session_end && $program->offeredClass->session_end < today()->toDateString(), $status, 'This program has ended.');
        return $program;
    }

    private function quoteFor(?OfferedClass $class): array
    {
        $price = $class?->price ?? $class?->Price;
        $discount = $class?->discount_price;
        if (!is_numeric($price) || (float) $price <= 0) return ['price' => 0, 'discount_amount' => 0, 'discount_percentage' => 0, 'final_amount' => 0];
        $price = round((float) $price, 2);
        $amount = is_numeric($discount) && (float) $discount > 0 ? round((float) $discount, 2) : $price;
        if ($amount > $price) return ['price' => $price, 'discount_amount' => 0, 'discount_percentage' => 0, 'final_amount' => 0];
        $discountAmount = round($price - $amount, 2);
        return ['price' => $price, 'discount_amount' => $discountAmount, 'discount_percentage' => round($discountAmount / $price * 100, 2), 'final_amount' => $amount];
    }

    private function validateStoredPrice(SubscriptionPaymentRequest $payment): void
    {
        $price = (float) $payment->price;
        $discount = (float) $payment->discount_amount;
        $amount = (float) $payment->final_amount;
        abort_unless(is_finite($price) && is_finite($discount) && is_finite($amount) && $price > 0 && $amount > 0 && $discount >= 0 && abs(($price - $discount) - $amount) < 0.011,
            409, 'The stored payment amount is inconsistent.');
    }

    private function assertNoOpenOrActiveSubscription(int $studentId, int $programId): void
    {
        abort_if(SubscriptionPaymentRequest::where('user_id', $studentId)->where('offered_program_id', $programId)->where('status', 'pending')->exists(), 409, 'A payment request is already pending for this offering.');
        abort_if(UserSubscription::where('user_id', $studentId)->where('offered_program_id', $programId)->where('status', 'active')->where(fn ($q) => $q->whereNull('expires_at')->orWhereDate('expires_at', '>=', today()))->exists(), 409, 'You already have an active subscription.');
    }
}
