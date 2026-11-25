<?php

namespace App\Http\Services;

use App\Http\Services\AwsUploadService;
use App\Models\FinancialTransaction;
use App\Models\PaymentReceipt;
use App\Models\SubscriptionPaymentRequest;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class ReceiptService
{
    /**
     * Generate atomic, collision-free receipt number: REC-YYYY-XXXXX
     */
    public function generateReceiptNumber(): string
    {
        $year = date('Y');
        $prefix = "REC-{$year}-";

        return DB::transaction(function () use ($prefix, $year) {
            // Find highest current receipt number for this year
            $latest = PaymentReceipt::where('receipt_number', 'like', "{$prefix}%")
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            $nextSequence = 1;
            if ($latest && preg_match('/^REC-\d{4}-(\d+)$/', $latest->receipt_number, $matches)) {
                $nextSequence = ((int) $matches[1]) + 1;
            }

            // Verify collision safety
            do {
                $candidate = sprintf('%s%05d', $prefix, $nextSequence);
                $exists = PaymentReceipt::where('receipt_number', $candidate)->exists();
                if (! $exists) {
                    return $candidate;
                }
                $nextSequence++;
            } while (true);
        });
    }

    /**
     * Issue or retrieve existing receipt for an approved subscription payment.
     */
    public function issueReceipt(
        SubscriptionPaymentRequest $payment,
        ?FinancialTransaction $transaction = null
    ): PaymentReceipt {
        return DB::transaction(function () use ($payment, $transaction) {
            $payment->loadMissing(['user', 'offeredProgram.offeredClass.userClass', 'paymentAccount', 'teacherProfile.webUser']);

            $existing = PaymentReceipt::where('subscription_payment_request_id', $payment->id)->first();
            if ($existing) {
                // Ensure S3 / local PDF exists
                if (! $existing->pdf_path || (! Storage::disk('s3')->exists($existing->pdf_path) && ! Storage::disk('local')->exists($existing->pdf_path))) {
                    $this->generatePdfFile($existing, $payment, $transaction);
                }
                return $existing;
            }

            $receiptNumber = $this->generateReceiptNumber();

            $channel = 'Direct Account';
            if ($payment->payment_method === 'teacher') {
                $teacherName = $payment->teacherProfile?->webUser?->name ?? 'Authorized Teacher';
                $code = $payment->collection_code_snapshot ?? $payment->teacherProfile?->collection_code;
                $channel = "Teacher Collection ({$teacherName} - {$code})";
            } elseif ($payment->paymentAccount) {
                $channel = $payment->paymentAccount->bank_name ?: $payment->paymentAccount->title;
            }

            $studentEmail = $payment->user?->email;

            $receipt = PaymentReceipt::create([
                'receipt_number' => $receiptNumber,
                'subscription_payment_request_id' => $payment->id,
                'financial_transaction_id' => $transaction?->id,
                'user_id' => $payment->user_id,
                'amount' => $payment->final_amount,
                'currency' => 'PKR',
                'payment_method' => $payment->payment_method,
                'payment_channel' => $channel,
                'transaction_reference' => $payment->transaction_id ?: $payment->receipt_number,
                'issued_at' => now(),
                'email_sent_to' => $studentEmail,
                'email_status' => 'pending',
                'status' => 'issued',
                'notes' => "Official subscription payment receipt for student #{$payment->user_id}",
            ]);

            // Sync receipt_number back to payment request
            $payment->update(['receipt_number' => $receiptNumber]);

            // Generate and save PDF file
            $this->generatePdfFile($receipt, $payment, $transaction);

            return $receipt;
        });
    }

    /**
     * Render and upload the PDF file to AWS S3 (with CDN availability).
     */
    public function generatePdfFile(
        PaymentReceipt $receipt,
        SubscriptionPaymentRequest $payment,
        ?FinancialTransaction $transaction = null
    ): string {
        $payment->loadMissing(['user', 'offeredProgram.offeredClass.userClass', 'paymentAccount', 'teacherProfile.webUser']);
        $student = $payment->user;
        $offering = $payment->offeredProgram;

        $pdf = Pdf::loadView('receipts.payment_receipt', [
            'receipt' => $receipt,
            'payment' => $payment,
            'student' => $student,
            'offering' => $offering,
            'transaction' => $transaction ?: $payment->financialTransaction,
        ]);

        $pdf->setPaper('a4', 'portrait');
        $pdfOutput = $pdf->output();

        $s3Directory = 'receipts/' . date('Y') . '/' . date('m');
        $fileName = "{$receipt->receipt_number}.pdf";

        // Upload to AWS S3 using AwsUploadService
        $aws = app(AwsUploadService::class);
        $s3Key = $aws->uploadFileToS3($pdfOutput, 'pdf', $s3Directory, $fileName);

        if (! $s3Key) {
            $envPrefix = app()->environment('production') ? 'prod' : 'dev';
            $s3Key = "{$envPrefix}/{$s3Directory}/{$fileName}";
            Storage::disk('s3')->put($s3Key, $pdfOutput, [
                'ContentType' => 'application/pdf',
                'CacheControl' => 'private, no-store, no-cache, max-age=0',
            ]);
        }

        // Also save local copy as fallback
        Storage::disk('local')->put("receipts/{$fileName}", $pdfOutput);

        $receipt->update(['pdf_path' => $s3Key]);

        return $s3Key;
    }

    /**
     * Get or build the PDF object for streaming/downloading.
     */
    public function getPdf(PaymentReceipt $receipt): \Barryvdh\DomPDF\PDF
    {
        $payment = $receipt->paymentRequest()->with(['user', 'offeredProgram.offeredClass.userClass', 'paymentAccount', 'teacherProfile.webUser'])->firstOrFail();
        $student = $payment->user;
        $offering = $payment->offeredProgram;

        $pdf = Pdf::loadView('receipts.payment_receipt', [
            'receipt' => $receipt,
            'payment' => $payment,
            'student' => $student,
            'offering' => $offering,
            'transaction' => $receipt->financialTransaction ?: $payment->financialTransaction,
        ]);

        $pdf->setPaper('a4', 'portrait');

        return $pdf;
    }
}
