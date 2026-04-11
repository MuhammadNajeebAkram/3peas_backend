<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use App\Models\SubscriptionPaymentRequest;
use App\Http\Services\AwsUploadService;

class UserPaymentController extends Controller
{
    protected $awsUploadService;

    public function __construct()
    {
        $this->awsUploadService = new AwsUploadService();
    }

    public function submitPaymentRequest(Request $request){
        $userId = auth('web_api')->id();

        try {
            $validator = Validator::make($request->all(), [
                'offered_program_id' => 'required|exists:offered_programs,id',
                'payment_account_id' => 'nullable|exists:payment_accounts,id',
                'amount' => 'required|numeric|min:0',
                'transaction_id' => 'nullable|string|unique:subscription_payment_requests,transaction_id',
                'payer_name' => 'nullable|string|max:255',
                'payer_phone' => 'nullable|string|max:20',
                'proof_file' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:2048',
            ]);

            if ($validator->fails()) {
                Log::warning('Payment request validation failed', [
                    'user_id' => $userId,
                    'errors' => $validator->errors()->toArray(),
                    'offered_program_id' => $request->input('offered_program_id'),
                    'payment_account_id' => $request->input('payment_account_id'),
                    'transaction_id' => $request->input('transaction_id'),
                ]);

                return response()->json([
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $validatedData = $validator->validated();
            $proofFilePath = null;

            if ($request->hasFile('proof_file')) {
                $proofFile = $request->file('proof_file');
                $s3Directory = 'payment_proofs/' . date('Y') . '/' . date('m');
                $fileName = 'payment-slip-' . $userId . '-' . time() . '.' . $proofFile->getClientOriginalExtension();
                $uploadedS3Key = $this->awsUploadService->uploadFileToS3(
                    $proofFile,
                    $proofFile->getClientOriginalExtension(),
                    $s3Directory,
                    $fileName
                );

                if (!$uploadedS3Key) {
                    Log::error('Payment slip upload failed', [
                        'user_id' => $userId,
                        'offered_program_id' => $validatedData['offered_program_id'],
                        'file_name' => $fileName,
                        'directory' => $s3Directory,
                    ]);

                    return response()->json([
                        'message' => 'Payment slip upload failed. Please try again.',
                    ], 500);
                }

                $proofFilePath = $uploadedS3Key;
            }

            $paymentRequest = SubscriptionPaymentRequest::create([
                'user_id' => $userId,
                'offered_program_id' => $validatedData['offered_program_id'],
                'payment_account_id' => $validatedData['payment_account_id'] ?? null,                
                'final_amount' => $validatedData['amount'],
                'price' => $validatedData['amount'],
                'transaction_id' => $validatedData['transaction_id'] ?? null,
                'payer_name' => $validatedData['payer_name'] ?? null,
                'payer_phone' => $validatedData['payer_phone'] ?? null,
                'proof_file_path' => $proofFilePath,
                'status' => 'pending',
            ]);

            return response()->json([
                'message' => 'Payment request submitted successfully',
                'payment_request' => $paymentRequest,
            ], 201);
        } catch (\Throwable $e) {
            Log::error('Failed to submit payment request', [
                'user_id' => $userId,
                'offered_program_id' => $request->input('offered_program_id'),
                'payment_account_id' => $request->input('payment_account_id'),
                'transaction_id' => $request->input('transaction_id'),
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'message' => 'Unable to submit payment request at the moment.',
            ], 500);
        }
    }
}
