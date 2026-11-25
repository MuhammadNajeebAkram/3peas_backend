<?php

namespace App\Mail;

use App\Models\PaymentReceipt;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class PaymentReceiptMailable extends Mailable
{
    use Queueable, SerializesModels;

    public PaymentReceipt $receipt;

    public function __construct(PaymentReceipt $receipt)
    {
        $this->receipt = $receipt;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Official Payment Receipt - {$this->receipt->receipt_number} | The Students Times",
        );
    }

    public function content(): Content
    {
        $payment = $this->receipt->paymentRequest()->with(['user', 'offeredProgram'])->first();

        return new Content(
            view: 'emails.payment_receipt',
            with: [
                'receipt' => $this->receipt,
                'payment' => $payment,
                'student' => $payment?->user,
                'offering' => $payment?->offeredProgram,
            ],
        );
    }

    public function attachments(): array
    {
        $attachments = [];

        if ($this->receipt->pdf_path) {
            if (Storage::disk('s3')->exists($this->receipt->pdf_path)) {
                $attachments[] = Attachment::fromData(
                    fn () => Storage::disk('s3')->get($this->receipt->pdf_path),
                    "Receipt-{$this->receipt->receipt_number}.pdf"
                )->withMime('application/pdf');
            } elseif (Storage::disk('local')->exists($this->receipt->pdf_path)) {
                $attachments[] = Attachment::fromPath(Storage::disk('local')->path($this->receipt->pdf_path))
                    ->as("Receipt-{$this->receipt->receipt_number}.pdf")
                    ->withMime('application/pdf');
            }
        }

        return $attachments;
    }
}
