<?php

namespace App\Http\Services;

use App\Models\SubscriptionPaymentRequest;
use App\Models\TeacherProfile;
use App\Models\TeacherSettlement;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class TeacherSettlementService
{
    public static function cents(string|int|float $amount): int
    {
        $parts = explode('.', (string) $amount);
        return ((int) $parts[0] * 100) + (int) str_pad($parts[1] ?? '', 2, '0');
    }

    public static function money(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }

    public function allocations(array $statuses = ['pending', 'confirmed'])
    {
        return DB::table('teacher_settlement_allocations as a')
            ->join('teacher_settlements as s', 's.id', '=', 'a.teacher_settlement_id')
            ->whereIn('s.status', $statuses);
    }

    public function collections(int $teacherId)
    {
        return SubscriptionPaymentRequest::where('teacher_profile_id', $teacherId)->where('payment_method', 'teacher')
            ->where('status', 'approved')->whereNotNull('confirmed_at')
            ->select(['id', 'user_id', 'offered_program_id', 'final_amount', 'receipt_number', 'confirmed_at'])
            ->selectSub($this->allocations(['confirmed'])->whereColumn('a.payment_request_id', 'subscription_payment_requests.id')->selectRaw('COALESCE(SUM(a.amount), 0)'), 'settled_amount')
            ->selectSub($this->allocations(['pending'])->whereColumn('a.payment_request_id', 'subscription_payment_requests.id')->selectRaw('COALESCE(SUM(a.amount), 0)'), 'pending_settlement_amount')
            ->with(['user:id,name', 'offeredProgram:id,title']);
    }

    public function summary(int $teacherId): array
    {
        $payments = SubscriptionPaymentRequest::where('teacher_profile_id', $teacherId)->where('payment_method', 'teacher');
        $collected = self::cents((clone $payments)->where('status', 'approved')->whereNotNull('confirmed_at')->sum('final_amount'));
        $settled = self::cents(TeacherSettlement::where('teacher_profile_id', $teacherId)->where('status', 'confirmed')->sum('amount'));
        $pending = self::cents(TeacherSettlement::where('teacher_profile_id', $teacherId)->where('status', 'pending')->sum('amount'));
        return ['currency' => 'PKR', 'collected' => self::money($collected), 'settled' => self::money($settled),
            'outstanding' => self::money($collected - $settled), 'pending_settlement' => self::money($pending),
            'available_to_settle' => self::money($collected - $settled - $pending),
            'pending_payment_requests' => (clone $payments)->where('status', 'pending')->count(),
            'confirmed_payment_requests' => (clone $payments)->where('status', 'approved')->count()];
    }

    public function submit(int $teacherId, array $data, ?UploadedFile $proof): TeacherSettlement
    {
        $path = null;
        try {
            return DB::transaction(function () use ($teacherId, $data, $proof, &$path) {
                // All allocations and reviews serialize on the teacher row, including partial settlements.
                TeacherProfile::lockForUpdate()->findOrFail($teacherId);
                $existing = TeacherSettlement::where('teacher_profile_id', $teacherId)->where('submission_key', $data['submission_key'])->first();
                if ($existing) {
                    $expected = collect($data['allocations'])->mapWithKeys(fn ($a) => [$a['payment_request_id'] => self::cents($a['amount'])])->sortKeys()->all();
                    $actual = $existing->allocations->mapWithKeys(fn ($a) => [$a->payment_request_id => self::cents($a->amount)])->sortKeys()->all();
                    abort_unless($expected === $actual && $existing->payment_method === $data['payment_method']
                        && (int) $existing->payment_account_id === (int) ($data['payment_account_id'] ?? 0)
                        && $existing->transaction_reference === ($data['transaction_reference'] ?? null)
                        && $existing->paid_at->toDateString() === $data['paid_at'], 409, 'Submission key was already used for a different settlement.');
                    return $existing;
                }
                if ($data['payment_method'] !== 'cash') {
                    $account = DB::table('payment_accounts')->where('id', $data['payment_account_id'])->where('is_active', true)->first();
                    $expectedMethod = $data['payment_method'] === 'bank' ? 'bank_deposit' : 'jazzcash';
                    abort_unless($account && strtolower($account->method) === $expectedMethod, 422, 'Choose an active account matching the settlement method.');
                    abort_if(TeacherSettlement::where('payment_account_id', $account->id)->where('transaction_reference', $data['transaction_reference'])->exists(), 409, 'This transfer reference has already been submitted.');
                }
                $total = 0;
                foreach ($data['allocations'] as $allocation) {
                    $payment = SubscriptionPaymentRequest::where('teacher_profile_id', $teacherId)->where('payment_method', 'teacher')
                        ->where('status', 'approved')->whereNotNull('confirmed_at')->find($allocation['payment_request_id']);
                    abort_unless($payment, 422, 'Allocations must reference this teacher’s confirmed collections.');
                    $reserved = self::cents($this->allocations()->where('a.payment_request_id', $payment->id)->sum('a.amount'));
                    $cents = self::cents($allocation['amount']);
                    abort_if($cents > self::cents($payment->final_amount) - $reserved, 422, 'An allocation exceeds the available collection balance.');
                    $total += $cents;
                }
                abort_if($total > 999999999999, 422, 'Settlement amount is too large.');
                if ($proof) {
                    $path = $proof->store('teacher-settlement-proofs', 'local');
                    abort_unless($path, 503, 'Could not store the settlement proof.');
                }
                $settlement = TeacherSettlement::create([
                    'teacher_profile_id' => $teacherId, 'submission_key' => $data['submission_key'],
                    'amount' => self::money($total), 'currency' => 'PKR', 'payment_method' => $data['payment_method'],
                    'payment_account_id' => $data['payment_account_id'] ?? null,
                    'transaction_reference' => $data['transaction_reference'] ?? null, 'paid_at' => $data['paid_at'],
                    'notes' => $data['notes'] ?? null, 'proof_path' => $path, 'status' => 'pending',
                ]);
                foreach ($data['allocations'] as $allocation) {
                    $settlement->allocations()->create(['payment_request_id' => $allocation['payment_request_id'],
                        'amount' => self::money(self::cents($allocation['amount']))]);
                }
                return $settlement->load('allocations');
            });
        } catch (\Throwable $e) {
            if ($path) Storage::disk('local')->delete($path);
            throw $e;
        }
    }

    public function review(int $id, int $adminId, string $status, ?string $reason): TeacherSettlement
    {
        return DB::transaction(function () use ($id, $adminId, $status, $reason) {
            $teacherId = TeacherSettlement::findOrFail($id)->teacher_profile_id;
            TeacherProfile::whereKey($teacherId)->lockForUpdate()->firstOrFail();
            $settlement = TeacherSettlement::lockForUpdate()->findOrFail($id);
            if ($settlement->status === $status) return $settlement->load('allocations');
            abort_unless($settlement->status === 'pending', 409, 'Only pending settlements can be reviewed.');
            // Pending allocations reserve funds, so confirmation cannot over-settle a collection.
            $settlement->update(['status' => $status, 'reviewed_by' => $adminId, 'reviewed_at' => now(),
                'rejection_reason' => $status === 'rejected' ? $reason : null]);
            return $settlement->load('allocations');
        });
    }
}
