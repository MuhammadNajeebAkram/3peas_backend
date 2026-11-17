<?php

namespace App\Http\Controllers;

use App\Models\SubscriptionPaymentRequest;
use App\Models\TeacherProfile;
use Illuminate\Support\Facades\Storage;

class PaymentEvidenceController extends Controller
{
    public function student(int $id)
    {
        $payment = SubscriptionPaymentRequest::findOrFail($id);
        abort_unless((int) $payment->user_id === (int) auth('web_api')->id(), 404);
        return $this->file($payment);
    }

    public function teacher(int $id)
    {
        $profile = app(TeacherAccountController::class)->profile();
        $payment = SubscriptionPaymentRequest::where('payment_method', 'teacher')->where('teacher_profile_id', $profile->id)->findOrFail($id);
        return $this->file($payment);
    }

    public function admin(int $id)
    {
        return $this->file(SubscriptionPaymentRequest::findOrFail($id));
    }

    public function adminTeacherPayment(int $id, int $paymentId)
    {
        TeacherProfile::findOrFail($id);
        $payment = SubscriptionPaymentRequest::where('payment_method', 'teacher')
            ->where('teacher_profile_id', $id)
            ->findOrFail($paymentId);
        return $this->file($payment);
    }

    private function file(SubscriptionPaymentRequest $payment)
    {
        abort_unless($payment->proof_file_path && Storage::disk('s3')->exists($payment->proof_file_path), 404);
        $extension = strtolower(pathinfo($payment->proof_file_path, PATHINFO_EXTENSION));
        $mime = match ($extension) { 'jpg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'pdf' => 'application/pdf', default => 'application/octet-stream' };
        return response(Storage::disk('s3')->get($payment->proof_file_path))
            ->header('Content-Type', $mime)->header('Content-Disposition', 'inline; filename="payment-proof.'.$extension.'"')
            ->header('X-Content-Type-Options', 'nosniff')->header('Cache-Control', 'private, no-store');
    }
}
