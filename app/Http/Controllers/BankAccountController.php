<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BankAccountController extends Controller
{
    //
    public function getBankAccounts(Request $request){
        try{
            $accounts = DB::table('bank_accounts_tbl')
            ->where('activate', 1)
            ->select('id', 'bank_name', 'account_name', 'account_no')
            ->get();

            return response()->json([
                'success' => 1,
                'accounts' => $accounts,
            ]);

        }catch(\Exception $e){

            return response()->json([
                'success' => -1,
                'error' => $e->getMessage(),
            ]);

        }
    }
}
