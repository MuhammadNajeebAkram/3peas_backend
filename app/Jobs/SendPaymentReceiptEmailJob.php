<?php

namespace App\Jobs;

use App\Mail\PaymentReceiptMailable;
use App\Models\PaymentReceipt;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SendPaymentReceiptEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $receiptId;
    public int $tries = 3;
    public int $backoff = 30;

    public function __construct(int $receiptId)
    {
        $this->receiptId = $receiptId;
        $this->afterCommit = true;
    }

    public function handle(): void
    {
        $receipt = PaymentReceipt::with(['user', 'paymentRequest.offeredProgram'])->find($this->receiptId);

        if (! $receipt) {
            Log::warning("SendPaymentReceiptEmailJob: Receipt #{$this->receiptId} not found.");
            return;
        }

        $email = $receipt->email_sent_to ?: $receipt->user?->email;

        if (empty($email)) {
            $receipt->update([
                'email_status' => 'failed',
                'notes' => trim(($receipt->notes ?? '') . ' | Email failed: No student email address available.'),
            ]);
            Log::warning("SendPaymentReceiptEmailJob: No recipient email for receipt {$receipt->receipt_number}");
            return;
        }

        try {
            Mail::to($email)->send(new PaymentReceiptMailable($receipt));

            $receipt->update([
                'email_sent_to' => $email,
                'email_sent_at' => now(),
                'email_status' => 'sent',
            ]);

            Log::info("SendPaymentReceiptEmailJob: Receipt {$receipt->receipt_number} successfully emailed to {$email}");
        } catch (Throwable $e) {
            Log::error("SendPaymentReceiptEmailJob: Error emailing receipt {$receipt->receipt_number} to {$email}: " . $e->getMessage());

            $receipt->update([
                'email_status' => 'failed',
                'notes' => trim(($receipt->notes ?? '') . " | Email attempt error: " . substr($e->getMessage(), 0, 150)),
            ]);

            throw $e;
        }
    }

    public function failed(Throwable $exception): void
    {
        $receipt = PaymentReceipt::find($this->receiptId);
        if ($receipt) {
            $receipt->update(['email_status' => 'failed']);
        }
        Log::error("SendPaymentReceiptEmailJob permanently failed for receipt #{$this->receiptId}: " . $exception->getMessage());
    }
}
