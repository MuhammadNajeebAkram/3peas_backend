<?php

namespace App\Http\Controllers\Teacher;

use App\Http\Controllers\Controller;
use App\Http\Controllers\TeacherAccountController;
use App\Http\Services\TeacherFinancialExportService;
use App\Http\Services\TeacherFinancialReportService;
use App\Models\TeacherProfile;
use Illuminate\Http\Request;

class TeacherFinancialController extends Controller
{
    public function __construct(
        private readonly TeacherFinancialReportService $reports,
        private readonly TeacherFinancialExportService $exports
    ) {
    }

    private function resolveTeacherWithAccess(): array
    {
        /** @var TeacherProfile $teacher */
        $teacher = app(TeacherAccountController::class)->profile();
        $access = $this->reports->assertCanAccess($teacher);

        return [$teacher, $access];
    }

    private function validateFilters(Request $request, array $extraRules = []): array
    {
        $rules = array_merge([
            'teacher_id' => 'prohibited',
            'teacher_profile_id' => 'prohibited',
            'from' => 'nullable|date_format:Y-m-d',
            'to' => 'nullable|date_format:Y-m-d|after_or_equal:from',
            'group_by' => 'nullable|in:day,week,month',
            'status' => 'nullable|in:pending,approved,rejected,confirmed',
            'payment_method' => 'nullable|in:teacher,cash,bank,jazzcash',
            'offered_program_id' => 'nullable|integer|min:1',
            'receipt_number' => 'nullable|string|max:100',
            'search' => 'nullable|string|max:120',
            'per_page' => 'nullable|integer|min:1|max:100',
        ], $extraRules);

        return $request->validate($rules);
    }

    public function summary(Request $request)
    {
        [$teacher, $access] = $this->resolveTeacherWithAccess();
        $filters = $this->validateFilters($request);

        return response()->json([
            'access' => $access,
            'summary' => $this->reports->summary($teacher->id, $filters),
        ]);
    }

    public function chart(Request $request)
    {
        [$teacher, $access] = $this->resolveTeacherWithAccess();
        $filters = $this->validateFilters($request);

        return response()->json([
            'access' => $access,
            'chart' => $this->reports->chart($teacher->id, $filters),
        ]);
    }

    public function payments(Request $request)
    {
        [$teacher, $access] = $this->resolveTeacherWithAccess();
        $filters = $this->validateFilters($request, [
            'status' => 'nullable|in:pending,approved,rejected',
        ]);
        $perPage = (int) ($filters['per_page'] ?? 20);
        $paginator = $this->reports->payments($teacher->id, $filters, $perPage);

        return response()->json([
            'access' => $access,
            'summary' => $this->reports->summary($teacher->id, $filters),
            'payments' => $paginator,
        ]);
    }

    public function paymentDetail(Request $request, int $id)
    {
        $this->validateFilters($request);
        [$teacher, $access] = $this->resolveTeacherWithAccess();

        return response()->json([
            'access' => $access,
            'payment_request' => $this->reports->paymentDetail($teacher->id, $id),
        ]);
    }

    public function settlementSummary(Request $request)
    {
        [$teacher, $access] = $this->resolveTeacherWithAccess();
        $filters = $this->validateFilters($request);

        return response()->json([
            'access' => $access,
            'settlement_summary' => $this->reports->settlementSummary($teacher->id, $filters),
        ]);
    }

    public function settlements(Request $request)
    {
        [$teacher, $access] = $this->resolveTeacherWithAccess();
        $filters = $this->validateFilters($request, [
            'status' => 'nullable|in:pending,confirmed,rejected',
            'payment_method' => 'nullable|in:cash,bank,jazzcash',
        ]);
        $perPage = (int) ($filters['per_page'] ?? 20);
        $paginator = $this->reports->settlements($teacher->id, $filters, $perPage);

        return response()->json([
            'access' => $access,
            'settlement_summary' => $this->reports->settlementSummary($teacher->id, $filters),
            'settlements' => $paginator,
        ]);
    }

    public function report(Request $request)
    {
        [$teacher, $access] = $this->resolveTeacherWithAccess();
        $filters = $this->validateFilters($request);

        return response()->json([
            'access' => $access,
            'summary' => $this->reports->summary($teacher->id, $filters),
            'report' => $this->reports->report($teacher->id, $filters),
        ]);
    }

    public function export(Request $request)
    {
        [$teacher] = $this->resolveTeacherWithAccess();
        $filters = $this->validateFilters($request, [
            'format' => 'nullable|in:csv,xlsx,pdf',
        ]);
        $format = $filters['format'] ?? 'csv';

        $exported = $this->exports->export($teacher, $filters, $format);

        return response($exported['content'], 200, [
            'Content-Type' => $exported['mime'],
            'Content-Disposition' => 'attachment; filename="'.$exported['filename'].'"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
