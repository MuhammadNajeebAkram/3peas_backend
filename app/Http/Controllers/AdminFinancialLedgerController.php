<?php

namespace App\Http\Controllers;

use App\Http\Services\FinancialLedgerService;
use App\Models\FinancialTransaction;
use Illuminate\Http\Request;

class AdminFinancialLedgerController extends Controller
{
    protected FinancialLedgerService $ledgerService;

    public function __construct(FinancialLedgerService $ledgerService)
    {
        $this->ledgerService = $ledgerService;
    }

    public function overview(Request $request)
    {
        $filters = $request->validate([
            'type' => 'nullable|string|max:50',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date',
            'search' => 'nullable|string|max:100',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $data = $this->ledgerService->getLedgerOverview($filters);

        return response()->json($data);
    }

    public function showTransaction(int $id)
    {
        $transaction = FinancialTransaction::with(['entries.account', 'createdByUser:id,name,email', 'reference'])
            ->findOrFail($id);

        return response()->json([
            'transaction' => $transaction,
            'is_balanced' => $transaction->isBalanced(),
        ]);
    }

    public function recognizeRevenue(Request $request)
    {
        $data = $request->validate([
            'amount' => 'nullable|numeric|min:1',
            'period' => 'nullable|string|max:10',
            'notes' => 'nullable|string|max:255',
        ]);

        $adminId = (int) auth('api')->id();
        $transaction = $this->ledgerService->recognizeRevenue(
            $adminId,
            isset($data['amount']) ? (float) $data['amount'] : null,
            $data['period'] ?? null,
            $data['notes'] ?? null
        );

        return response()->json([
            'message' => 'Subscription revenue recognized successfully.',
            'transaction' => $transaction,
        ]);
    }

    public function reverseTransaction(Request $request, int $id)
    {
        $data = $request->validate([
            'reason' => 'required|string|min:3|max:255',
        ]);

        $adminId = (int) auth('api')->id();
        $reversal = $this->ledgerService->reverseTransaction(
            $id,
            $adminId,
            $data['reason']
        );

        return response()->json([
            'message' => 'Transaction reversed successfully with double-entry balancing.',
            'reversal' => $reversal,
        ]);
    }
}
