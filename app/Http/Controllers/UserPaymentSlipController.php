<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UserPaymentSlipController extends Controller
{
    //

    public function uploadPaymentSlip(Request $request)
{
    try {
        DB::beginTransaction();

        $user = $request->user();

        // Check if payment slip already exists
        $exist = DB::table('user_payment_slip_tbl')
            ->where('user_id', $user->id)
            ->exists(); // Using exists() instead of first() to improve efficiency

        if ($exist) {
            return response()->json([
                'success' => 0,
                'message' => 'Payment slip already exists.'
            ]);
        }

        // Insert payment slip
        DB::table('user_payment_slip_tbl')->insert([
            'user_id' => $user->id,
            'name' => $request->name,
            'bank_account_id' => $request->bank_account_id ?? 1, // Allow dynamic bank_account_id
            'activate' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Determine quantity
        $qty = empty($request->study_group) ? count($request->study_subjects ?? []) : 1;

        // Insert study plan
        DB::table('user_study_plan_tbl')->insert([
            'user_id' => $user->id,
            'study_plan_id' => $request->study_plan,
            'qty' => $qty,
            'price' => $request->price,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Insert selected subjects
        if (empty($request->study_group)) {
            if (!empty($request->study_subjects) && is_array($request->study_subjects)) {
                foreach ($request->study_subjects as $subject) {
                    DB::table('user_selected_subject_tbl')->insert([
                        'user_id' => $user->id,
                        'subject_id' => $subject,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        } else {
            $subjects = DB::table('study_group_detail_tbl')
                ->where('study_group_id', $request->study_group)
                ->pluck('subject_id') // Use pluck() for better efficiency
                ->toArray();

            foreach ($subjects as $subject) {
                DB::table('user_selected_subject_tbl')->insert([
                    'user_id' => $user->id,
                    'subject_id' => $subject,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
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
        ], 500);
    }
}

}
