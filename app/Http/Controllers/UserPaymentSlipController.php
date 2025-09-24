<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;

class UserPaymentSlipController extends Controller
{
    //

    public function uploadPaymentSlip(Request $request)
    {
        $user = Auth::guard('web_api')->user();
        try {
            DB::beginTransaction();
    
           
    
            // Check if payment slip already exists
            $exist = DB::table('user_payment_slip_tbl')
                ->where('user_id', $user->id)
                ->exists();
    
            if ($exist) {
                return response()->json([
                    'success' => 0,
                    'message' => 'Payment slip already exists.'
                ]);
            }
    
            // Common profile update
            DB::table('user_profile_tbl')
                ->where('user_id', $user->id)
                ->update([
                    'class_id' => $request->class_id,
                    'curriculum_board_id' => $request->curriculum_board_id,
                    'study_plan_id' => $request->study_plan,
                ]);
    
            if (!$request->is_trial) {
                // Insert payment slip
                DB::table('user_payment_slip_tbl')->insert([
                    'user_id' => $user->id,
                    'name' => $request->name,
                    'bank_account_id' => $request->bank_account_id ?? 1,
                    'transaction_id' => $request->transaction_id,
                    'amount' => $request->price,
                    'activate' => false,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                // Set study session for trial users
                $session = DB::table('study_plan_tbl')
                    ->where('id', $request->study_plan)
                    ->value('session_id'); // Use value() instead of select()->first()
    
                DB::table('web_users')
                    ->where('id', $user->id)
                    ->update([
                        'study_session_id' => $session,
                        'updated_at' => now(),
                    ]);
            }
    
            // Insert study plan mapping
            DB::table('user_study_plan_tbl')->insert([
                'user_id' => $user->id,
                'study_plan_id' => $request->study_plan,
                'qty' => 1,
                'price' => $request->price,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
    
            // Assign subjects
            $subjects = DB::table('study_group_detail_tbl')
                ->where('study_group_id', $request->study_group)
                ->pluck('subject_id');
    
            foreach ($subjects as $subjectId) {
                DB::table('user_selected_subject_tbl')->insert([
                    'user_id' => $user->id,
                    'subject_id' => $subjectId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
    
            DB::commit();
    
            return response()->json([
                'success' => 1,
                'message' => 'Payment slip uploaded successfully.',
            ]);
    
        } catch (\Exception $e) {
            DB::rollBack();
    
            return response()->json([
                'success' => -1,
                'error' => $e->getMessage(),
                'user' => $user,
            ], 500);
        }
    }

    public function uploadDepositSlipImage(Request $request)
    {
        // 1. Validate the incoming file
        $validator = Validator::make($request->all(), [
            'deposit_slip' => 'required|image|mimes:jpeg,png,jpg|max:1024', // Max 1MB
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => 0,
                'message' => 'Validation failed for image upload.',
                'errors' => $validator->errors(),
            ], 422);
        }

        // 2. Get the uploaded file
        $file = $request->file('deposit_slip');

        // 3. Define the S3 directory (folder)
        $s3Directory = 'deposit_slips';

        // 4. Generate a unique filename for S3
        $filename = time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();

        // 5. Construct the full S3 key (path)
        $s3Key = $s3Directory . '/' . $filename;

        try {
            // 6. Store the file on S3 disk
            Storage::disk('s3')->putFileAs(
                $s3Directory,
                $file,
                $filename,
                'public'
            );

            // 7. Return the S3 key and public URL to the client
            return response()->json([
                'success' => 1,
                'message' => 'Deposit slip uploaded successfully.',
                's3_key' => $s3Key,
                'url' => Storage::disk('s3')->url($s3Key),
                'file_size' => $file->getSize(),
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => 0,
                'message' => 'Failed to upload deposit slip to S3.',
                'debug' => [
                    'error' => $e->getMessage(),
                    'line' => $e->getLine(),
                    'file' => $e->getFile(),
                ]
            ], 500);
        }
    }
    

}
