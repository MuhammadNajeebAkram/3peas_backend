<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\PaymentAccount;

class PaymentAccountController extends Controller
{
    //
    public function getPaymentBankAccounts(){
        $paymentAccounts = PaymentAccount::where('is_active', true)
        ->whereNot('method', 'cash')
        ->get(['id', 'bank_name', 'account_number', 'account_title', 'method', 'title']);

        return response()->json($paymentAccounts);
    }
}
