<?php

namespace App\Http\Services;

use App\Models\SubscriptionPaymentRequest;
use App\Models\TeacherProfile;
use App\Models\TeacherSettlement;
use Carbon\Carbon;
use ZipArchive;

class TeacherFinancialExportService
{
    public function __construct(
        private readonly TeacherFinancialReportService $reports,
        private readonly TeacherSettlementService $settlements
    ) {
    }

    public function export(TeacherProfile $teacher, array $filters, string $format): array
    {
        $teacher->loadMissing('webUser:id,name,email');
        $generatedAt = Carbon::now()->format('Y-m-d H:i:s');
        $teacherName = $teacher->webUser?->name ?: 'Teacher #'.$teacher->id;
        $teacherCode = $teacher->teacher_code ?: 'TCH-'.$teacher->id;
        $collectionCode = $teacher->collection_code ?: 'N/A';
        $periodLabel = $this->formatPeriodLabel($filters);

        $summary = $this->reports->summary($teacher->id, $filters);
        $report = $this->reports->report($teacher->id, $filters);
        $paymentRows = $this->collectPaymentRows($teacher->id, $filters);
        $settlementRows = $this->collectSettlementRows($teacher->id, $filters);

        $safeCode = preg_replace('/[^A-Za-z0-9_-]/', '_', $teacherCode);
        $dateSlug = Carbon::now()->format('Ymd_His');

        return match ($format) {
            'xlsx' => [
                'content' => $this->buildXlsx($teacherName, $teacherCode, $collectionCode, $generatedAt, $periodLabel, $summary, $report, $paymentRows, $settlementRows),
                'filename' => "teacher_financial_report_{$safeCode}_{$dateSlug}.xlsx",
                'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ],
            'pdf' => [
                'content' => $this->buildPdf($teacherName, $teacherCode, $collectionCode, $generatedAt, $periodLabel, $summary, $report, $paymentRows, $settlementRows),
                'filename' => "teacher_financial_statement_{$safeCode}_{$dateSlug}.pdf",
                'mime' => 'application/pdf',
            ],
            default => [
                'content' => $this->buildCsv($teacherName, $teacherCode, $collectionCode, $generatedAt, $periodLabel, $summary, $report, $paymentRows, $settlementRows),
                'filename' => "teacher_financial_report_{$safeCode}_{$dateSlug}.csv",
                'mime' => 'text/csv; charset=UTF-8',
            ],
        };
    }

    private function formatPeriodLabel(array $filters): string
    {
        $from = $filters['from'] ?? null;
        $to = $filters['to'] ?? null;
        if ($from && $to) {
            return "{$from} to {$to}";
        }
        if ($from) {
            return "From {$from}";
        }
        if ($to) {
            return "Up to {$to}";
        }
        return 'All Time';
    }

    private function collectPaymentRows(int $teacherId, array $filters): array
    {
        $rows = [];
        $totalPriceCents = 0;
        $totalDiscountCents = 0;
        $totalFinalCents = 0;
        $totalSettledCents = 0;

        $query = $this->reports->basePaymentsQuery($teacherId, $filters)
            ->select('subscription_payment_requests.*')
            ->selectSub(
                $this->settlements->allocations(['confirmed'])
                    ->whereColumn('a.payment_request_id', 'subscription_payment_requests.id')
                    ->selectRaw('COALESCE(SUM(a.amount), 0)'),
                'settled_amount'
            )
            ->selectSub(
                $this->settlements->allocations(['pending'])
                    ->whereColumn('a.payment_request_id', 'subscription_payment_requests.id')
                    ->selectRaw('COALESCE(SUM(a.amount), 0)'),
                'pending_settlement_amount'
            )
            ->with([
                'user:id,name,email,phone',
                'offeredProgram:id,title,offered_class_id',
                'offeredProgram.offeredClass:id,class_id,session_start,session_end',
                'offeredProgram.offeredClass.userClass:id,class_name',
            ])
            ->orderBy('subscription_payment_requests.id', 'asc');

        $query->chunk(200, function ($chunk) use (&$rows, &$totalPriceCents, &$totalDiscountCents, &$totalFinalCents, &$totalSettledCents) {
            foreach ($chunk as $payment) {
                /** @var SubscriptionPaymentRequest $payment */
                $formatted = $this->reports->formatPaymentRow($payment);
                $priceCents = TeacherSettlementService::cents($formatted['price']);
                $discountCents = TeacherSettlementService::cents($formatted['discount_amount']);
                $finalCents = TeacherSettlementService::cents($formatted['final_amount']);
                $settledCents = TeacherSettlementService::cents($formatted['settled_amount']);

                $totalPriceCents += $priceCents;
                $totalDiscountCents += $discountCents;
                $totalFinalCents += $finalCents;
                $totalSettledCents += $settledCents;

                $rows[] = [
                    'id' => $formatted['id'],
                    'receipt_number' => $formatted['receipt_number'] ?: $formatted['transaction_id'],
                    'student_name' => $formatted['user']['name'] ?? 'Student #'.$formatted['user_id'],
                    'program_title' => $formatted['offered_program']['title'] ?? 'Program #'.$formatted['offered_program_id'],
                    'class_name' => $formatted['offered_program']['class_name'] ?? '',
                    'price' => $formatted['price'],
                    'discount_amount' => $formatted['discount_amount'],
                    'final_amount' => $formatted['final_amount'],
                    'settled_amount' => $formatted['settled_amount'],
                    'payment_method' => $formatted['payment_method'],
                    'status' => $formatted['status'],
                    'reviewer_type' => $formatted['reviewer_type'] ?? '',
                    'created_at' => $payment->created_at ? Carbon::parse($payment->created_at)->format('Y-m-d H:i') : '',
                    'confirmed_at' => $payment->confirmed_at ? Carbon::parse($payment->confirmed_at)->format('Y-m-d H:i') : '',
                    'remarks' => $formatted['rejection_reason'] ?: ($formatted['admin_remarks'] ?? ''),
                ];
            }
        });

        return [
            'items' => $rows,
            'totals' => [
                'count' => count($rows),
                'price' => TeacherSettlementService::money($totalPriceCents),
                'discount_amount' => TeacherSettlementService::money($totalDiscountCents),
                'final_amount' => TeacherSettlementService::money($totalFinalCents),
                'settled_amount' => TeacherSettlementService::money($totalSettledCents),
            ],
        ];
    }

    private function collectSettlementRows(int $teacherId, array $filters): array
    {
        $rows = [];
        $totalAmountCents = 0;

        $settlementFilters = array_intersect_key($filters, array_flip(['from', 'to', 'status', 'payment_method']));
        $query = $this->reports->baseSettlementsQuery($teacherId, $settlementFilters)
            ->with([
                'allocations.paymentRequest:id,receipt_number,final_amount',
                'paymentAccount:id,title,bank_name,account_number',
            ])
            ->orderBy('teacher_settlements.id', 'asc');

        $query->chunk(200, function ($chunk) use (&$rows, &$totalAmountCents) {
            foreach ($chunk as $settlement) {
                /** @var TeacherSettlement $settlement */
                $formatted = $this->reports->formatSettlementRow($settlement);
                $amountCents = TeacherSettlementService::cents($formatted['amount']);
                $totalAmountCents += $amountCents;

                $allocSummary = collect($formatted['allocations'])
                    ->map(fn ($a) => ($a['receipt_number'] ?: '#'.$a['payment_request_id']).' (PKR '.$a['amount'].')')
                    ->implode('; ');

                $rows[] = [
                    'id' => $formatted['id'],
                    'settlement_reference' => $formatted['settlement_reference'],
                    'amount' => $formatted['amount'],
                    'payment_method' => $formatted['payment_method'],
                    'transaction_reference' => $formatted['transaction_reference'] ?? '',
                    'paid_at' => $formatted['paid_at'] ?? '',
                    'reviewed_at' => $settlement->reviewed_at ? Carbon::parse($settlement->reviewed_at)->format('Y-m-d H:i') : '',
                    'status' => $formatted['status'],
                    'allocated_receipts' => $allocSummary,
                    'remarks' => $formatted['rejection_reason'] ?: ($formatted['notes'] ?? ''),
                ];
            }
        });

        return [
            'items' => $rows,
            'totals' => [
                'count' => count($rows),
                'amount' => TeacherSettlementService::money($totalAmountCents),
            ],
        ];
    }

    private function buildCsv(
        string $teacherName,
        string $teacherCode,
        string $collectionCode,
        string $generatedAt,
        string $periodLabel,
        array $summary,
        array $report,
        array $paymentRows,
        array $settlementRows
    ): string {
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, "\xEF\xBB\xBF");

        fputcsv($handle, ['THE STUDENTS TIMES - TEACHER FINANCIAL STATEMENT']);
        fputcsv($handle, ['Teacher Name', $teacherName]);
        fputcsv($handle, ['Teacher Code', $teacherCode]);
        fputcsv($handle, ['Collection Code', $collectionCode]);
        fputcsv($handle, ['Report Generation Date', $generatedAt]);
        fputcsv($handle, ['Selected Period', $periodLabel]);
        fputcsv($handle, ['Currency', 'PKR']);
        fputcsv($handle, []);

        fputcsv($handle, ['FINANCIAL SUMMARY']);
        fputcsv($handle, ['Metric', 'Count', 'Amount (PKR)']);
        fputcsv($handle, ['Total Confirmed Collections', $summary['confirmed_collections']['count'], $summary['confirmed_collections']['amount']]);
        fputcsv($handle, ['Pending Payment Requests', $summary['pending_payments']['count'], $summary['pending_payments']['amount']]);
        fputcsv($handle, ['Approved Payment Requests', $summary['approved_payments']['count'], $summary['approved_payments']['amount']]);
        fputcsv($handle, ['Rejected Payment Requests', $summary['rejected_payments']['count'], $summary['rejected_payments']['amount']]);
        fputcsv($handle, ['Total Remitted to Admin (Confirmed)', $summary['total_remitted']['count'], $summary['total_remitted']['amount']]);
        fputcsv($handle, ['Pending Remittances', $summary['pending_remittances']['count'], $summary['pending_remittances']['amount']]);
        fputcsv($handle, ['Outstanding Balance', '', $summary['outstanding_balance']['amount']]);
        fputcsv($handle, ['Available to Remit', '', $summary['available_to_remit']['amount']]);
        fputcsv($handle, []);

        fputcsv($handle, ['DATE-WISE SUMMARY ('.strtoupper($report['group_by']).')']);
        fputcsv($handle, ['Period', 'Total Requests', 'Confirmed Count', 'Confirmed Amount (PKR)', 'Pending Count', 'Rejected Count', 'Confirmed Remitted (PKR)', 'Net Balance (PKR)']);
        foreach ($report['rows'] as $r) {
            fputcsv($handle, [
                $r['period'],
                $r['total_payment_requests'],
                $r['confirmed_payment_count'],
                $r['confirmed_collection_amount'],
                $r['pending_request_count'],
                $r['rejected_request_count'],
                $r['confirmed_remittance_amount'],
                $r['outstanding_balance'],
            ]);
        }
        fputcsv($handle, [
            'TOTAL',
            $report['totals']['total_payment_requests'],
            $report['totals']['confirmed_payment_count'],
            $report['totals']['confirmed_collection_amount'],
            $report['totals']['pending_request_count'],
            $report['totals']['rejected_request_count'],
            $report['totals']['confirmed_remittance_amount'],
            $report['totals']['outstanding_balance'],
        ]);
        fputcsv($handle, []);

        fputcsv($handle, ['PAYMENT TRANSACTIONS']);
        fputcsv($handle, ['Receipt / Ref', 'Student Name', 'Offering', 'Class', 'Original Price (PKR)', 'Discount (PKR)', 'Final Amount (PKR)', 'Settled (PKR)', 'Method', 'Status', 'Reviewed By', 'Submitted At', 'Confirmed At', 'Remarks']);
        foreach ($paymentRows['items'] as $p) {
            fputcsv($handle, [
                $p['receipt_number'],
                $p['student_name'],
                $p['program_title'],
                $p['class_name'],
                $p['price'],
                $p['discount_amount'],
                $p['final_amount'],
                $p['settled_amount'],
                $p['payment_method'],
                $p['status'],
                $p['reviewer_type'],
                $p['created_at'],
                $p['confirmed_at'],
                $p['remarks'],
            ]);
        }
        fputcsv($handle, [
            'TOTAL ('.$paymentRows['totals']['count'].' rows)',
            '',
            '',
            '',
            $paymentRows['totals']['price'],
            $paymentRows['totals']['discount_amount'],
            $paymentRows['totals']['final_amount'],
            $paymentRows['totals']['settled_amount'],
            '',
            '',
            '',
            '',
            '',
            '',
        ]);
        fputcsv($handle, []);

        fputcsv($handle, ['SETTLEMENT HISTORY']);
        fputcsv($handle, ['Settlement ID', 'Amount (PKR)', 'Payment Method', 'Transaction Reference', 'Settlement Date', 'Reviewed At', 'Status', 'Allocated Receipts', 'Remarks']);
        foreach ($settlementRows['items'] as $s) {
            fputcsv($handle, [
                $s['settlement_reference'],
                $s['amount'],
                $s['payment_method'],
                $s['transaction_reference'],
                $s['paid_at'],
                $s['reviewed_at'],
                $s['status'],
                $s['allocated_receipts'],
                $s['remarks'],
            ]);
        }
        fputcsv($handle, [
            'TOTAL ('.$settlementRows['totals']['count'].' rows)',
            $settlementRows['totals']['amount'],
            '',
            '',
            '',
            '',
            '',
            '',
            '',
        ]);

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return (string) $csv;
    }

    private function buildXlsx(
        string $teacherName,
        string $teacherCode,
        string $collectionCode,
        string $generatedAt,
        string $periodLabel,
        array $summary,
        array $report,
        array $paymentRows,
        array $settlementRows
    ): string {
        $sheet1Rows = [
            ['THE STUDENTS TIMES - TEACHER FINANCIAL STATEMENT'],
            ['Teacher Name', $teacherName],
            ['Teacher Code', $teacherCode],
            ['Collection Code', $collectionCode],
            ['Report Generation Date', $generatedAt],
            ['Selected Period', $periodLabel],
            ['Currency', 'PKR'],
            [],
            ['FINANCIAL SUMMARY'],
            ['Metric', 'Count', 'Amount (PKR)'],
            ['Total Confirmed Collections', (string) $summary['confirmed_collections']['count'], $summary['confirmed_collections']['amount']],
            ['Pending Payment Requests', (string) $summary['pending_payments']['count'], $summary['pending_payments']['amount']],
            ['Approved Payment Requests', (string) $summary['approved_payments']['count'], $summary['approved_payments']['amount']],
            ['Rejected Payment Requests', (string) $summary['rejected_payments']['count'], $summary['rejected_payments']['amount']],
            ['Total Remitted to Admin (Confirmed)', (string) $summary['total_remitted']['count'], $summary['total_remitted']['amount']],
            ['Pending Remittances', (string) $summary['pending_remittances']['count'], $summary['pending_remittances']['amount']],
            ['Outstanding Balance', '', $summary['outstanding_balance']['amount']],
            ['Available to Remit', '', $summary['available_to_remit']['amount']],
            [],
            ['DATE-WISE SUMMARY ('.strtoupper($report['group_by']).')'],
            ['Period', 'Total Requests', 'Confirmed Count', 'Confirmed Amount (PKR)', 'Pending Count', 'Rejected Count', 'Confirmed Remitted (PKR)', 'Net Balance (PKR)'],
        ];
        foreach ($report['rows'] as $r) {
            $sheet1Rows[] = [
                $r['period'],
                (string) $r['total_payment_requests'],
                (string) $r['confirmed_payment_count'],
                $r['confirmed_collection_amount'],
                (string) $r['pending_request_count'],
                (string) $r['rejected_request_count'],
                $r['confirmed_remittance_amount'],
                $r['outstanding_balance'],
            ];
        }
        $sheet1Rows[] = [
            'TOTAL',
            (string) $report['totals']['total_payment_requests'],
            (string) $report['totals']['confirmed_payment_count'],
            $report['totals']['confirmed_collection_amount'],
            (string) $report['totals']['pending_request_count'],
            (string) $report['totals']['rejected_request_count'],
            $report['totals']['confirmed_remittance_amount'],
            $report['totals']['outstanding_balance'],
        ];

        $sheet2Rows = [
            ['PAYMENT TRANSACTIONS - '.$teacherName.' ('.$teacherCode.')'],
            ['Selected Period', $periodLabel, 'Generated At', $generatedAt],
            [],
            ['Receipt / Ref', 'Student Name', 'Offering', 'Class', 'Original Price (PKR)', 'Discount (PKR)', 'Final Amount (PKR)', 'Settled (PKR)', 'Method', 'Status', 'Reviewed By', 'Submitted At', 'Confirmed At', 'Remarks'],
        ];
        foreach ($paymentRows['items'] as $p) {
            $sheet2Rows[] = [
                $p['receipt_number'],
                $p['student_name'],
                $p['program_title'],
                $p['class_name'],
                $p['price'],
                $p['discount_amount'],
                $p['final_amount'],
                $p['settled_amount'],
                $p['payment_method'],
                $p['status'],
                $p['reviewer_type'],
                $p['created_at'],
                $p['confirmed_at'],
                $p['remarks'],
            ];
        }
        $sheet2Rows[] = [
            'TOTAL ('.$paymentRows['totals']['count'].' rows)',
            '',
            '',
            '',
            $paymentRows['totals']['price'],
            $paymentRows['totals']['discount_amount'],
            $paymentRows['totals']['final_amount'],
            $paymentRows['totals']['settled_amount'],
            '',
            '',
            '',
            '',
            '',
            '',
        ];

        $sheet3Rows = [
            ['SETTLEMENT HISTORY - '.$teacherName.' ('.$teacherCode.')'],
            ['Selected Period', $periodLabel, 'Generated At', $generatedAt],
            [],
            ['Settlement ID', 'Amount (PKR)', 'Payment Method', 'Transaction Reference', 'Settlement Date', 'Reviewed At', 'Status', 'Allocated Receipts', 'Remarks'],
        ];
        foreach ($settlementRows['items'] as $s) {
            $sheet3Rows[] = [
                $s['settlement_reference'],
                $s['amount'],
                $s['payment_method'],
                $s['transaction_reference'],
                $s['paid_at'],
                $s['reviewed_at'],
                $s['status'],
                $s['allocated_receipts'],
                $s['remarks'],
            ];
        }
        $sheet3Rows[] = [
            'TOTAL ('.$settlementRows['totals']['count'].' rows)',
            $settlementRows['totals']['amount'],
            '',
            '',
            '',
            '',
            '',
            '',
            '',
        ];

        $tmpFile = tempnam(sys_get_temp_dir(), 'tst_fin_xlsx_');
        $zip = new ZipArchive();
        $zip->open($tmpFile, ZipArchive::OVERWRITE);

        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet2.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet3.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .'</Types>');

        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>');

        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets>'
            .'<sheet name="Financial Summary" sheetId="1" r:id="rId1"/>'
            .'<sheet name="Payment Transactions" sheetId="2" r:id="rId2"/>'
            .'<sheet name="Settlement History" sheetId="3" r:id="rId3"/>'
            .'</sheets>'
            .'</workbook>');

        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/>'
            .'<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet3.xml"/>'
            .'</Relationships>');

        $zip->addFromString('xl/worksheets/sheet1.xml', $this->buildWorksheetXml($sheet1Rows));
        $zip->addFromString('xl/worksheets/sheet2.xml', $this->buildWorksheetXml($sheet2Rows));
        $zip->addFromString('xl/worksheets/sheet3.xml', $this->buildWorksheetXml($sheet3Rows));

        $zip->close();
        $binary = (string) file_get_contents($tmpFile);
        @unlink($tmpFile);

        return $binary;
    }

    private function buildWorksheetXml(array $rows): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';

        foreach ($rows as $rIdx => $row) {
            $rowNum = $rIdx + 1;
            $xml .= '<row r="'.$rowNum.'">';
            foreach ($row as $cIdx => $cellValue) {
                $colRef = $this->excelColumnName($cIdx + 1).$rowNum;
                $escaped = htmlspecialchars((string) $cellValue, ENT_XML1 | ENT_QUOTES, 'UTF-8');
                $xml .= '<c r="'.$colRef.'" t="inlineStr"><is><t xml:space="preserve">'.$escaped.'</t></is></c>';
            }
            $xml .= '</row>';
        }

        $xml .= '</sheetData></worksheet>';
        return $xml;
    }

    private function excelColumnName(int $index): string
    {
        $name = '';
        while ($index > 0) {
            $mod = ($index - 1) % 26;
            $name = chr(65 + $mod).$name;
            $index = intdiv($index - 1, 26);
        }
        return $name;
    }

    private function buildPdf(
        string $teacherName,
        string $teacherCode,
        string $collectionCode,
        string $generatedAt,
        string $periodLabel,
        array $summary,
        array $report,
        array $paymentRows,
        array $settlementRows
    ): string {
        $lines = [];
        $lines[] = ['bold' => true, 'size' => 13, 'text' => 'THE STUDENTS TIMES - TEACHER FINANCIAL STATEMENT'];
        $lines[] = ['bold' => false, 'size' => 9, 'text' => str_repeat('=', 95)];
        $lines[] = ['bold' => false, 'size' => 10, 'text' => "Teacher Name: {$teacherName}    |    Teacher Code: {$teacherCode}    |    Collection Code: {$collectionCode}"];
        $lines[] = ['bold' => false, 'size' => 10, 'text' => "Generated At: {$generatedAt}    |    Selected Period: {$periodLabel}    |    Currency: PKR"];
        $lines[] = ['bold' => false, 'size' => 9, 'text' => ''];

        $lines[] = ['bold' => true, 'size' => 11, 'text' => '1. FINANCIAL SUMMARY'];
        $lines[] = ['bold' => false, 'size' => 9, 'text' => str_repeat('-', 95)];
        $lines[] = ['bold' => false, 'size' => 10, 'text' => sprintf('Total Confirmed Collections : PKR %s (%d confirmed payments)', $summary['confirmed_collections']['amount'], $summary['confirmed_collections']['count'])];
        $lines[] = ['bold' => false, 'size' => 10, 'text' => sprintf('Pending Payment Requests    : PKR %s (%d requests)', $summary['pending_payments']['amount'], $summary['pending_payments']['count'])];
        $lines[] = ['bold' => false, 'size' => 10, 'text' => sprintf('Rejected Payment Requests   : PKR %s (%d requests)', $summary['rejected_payments']['amount'], $summary['rejected_payments']['count'])];
        $lines[] = ['bold' => false, 'size' => 10, 'text' => sprintf('Total Remitted to Admin     : PKR %s (%d confirmed settlements)', $summary['total_remitted']['amount'], $summary['total_remitted']['count'])];
        $lines[] = ['bold' => false, 'size' => 10, 'text' => sprintf('Pending Remittances         : PKR %s (%d pending settlements)', $summary['pending_remittances']['amount'], $summary['pending_remittances']['count'])];
        $lines[] = ['bold' => true, 'size' => 10, 'text' => sprintf('Outstanding Balance         : PKR %s', $summary['outstanding_balance']['amount'])];
        $lines[] = ['bold' => true, 'size' => 10, 'text' => sprintf('Available to Remit          : PKR %s', $summary['available_to_remit']['amount'])];
        $lines[] = ['bold' => false, 'size' => 9, 'text' => ''];

        $lines[] = ['bold' => true, 'size' => 11, 'text' => '2. DATE-WISE SUMMARY ('.strtoupper($report['group_by']).')'];
        $lines[] = ['bold' => false, 'size' => 9, 'text' => str_repeat('-', 95)];
        $lines[] = ['bold' => true, 'size' => 9, 'text' => 'Period          | Requests | Confirmed | Confirmed (PKR) | Remitted (PKR) | Net Balance (PKR)'];
        foreach ($report['rows'] as $r) {
            $lines[] = [
                'bold' => false,
                'size' => 9,
                'text' => sprintf(
                    '%-15s | %-8d | %-9d | %-15s | %-14s | %s',
                    substr($r['period'], 0, 15),
                    $r['total_payment_requests'],
                    $r['confirmed_payment_count'],
                    $r['confirmed_collection_amount'],
                    $r['confirmed_remittance_amount'],
                    $r['outstanding_balance']
                ),
            ];
        }
        $lines[] = [
            'bold' => true,
            'size' => 9,
            'text' => sprintf(
                '%-15s | %-8d | %-9d | %-15s | %-14s | %s',
                'TOTAL',
                $report['totals']['total_payment_requests'],
                $report['totals']['confirmed_payment_count'],
                $report['totals']['confirmed_collection_amount'],
                $report['totals']['confirmed_remittance_amount'],
                $report['totals']['outstanding_balance']
            ),
        ];
        $lines[] = ['bold' => false, 'size' => 9, 'text' => ''];

        $lines[] = ['bold' => true, 'size' => 11, 'text' => '3. PAYMENT TRANSACTIONS ('.$paymentRows['totals']['count'].' records, Total Final Amount: PKR '.$paymentRows['totals']['final_amount'].')'];
        $lines[] = ['bold' => false, 'size' => 9, 'text' => str_repeat('-', 95)];
        foreach ($paymentRows['items'] as $p) {
            $lines[] = [
                'bold' => false,
                'size' => 8,
                'text' => sprintf(
                    '%s | %s | %s | PKR %s (Disc: %s) | %s | %s',
                    $p['receipt_number'],
                    substr($p['student_name'], 0, 22),
                    substr($p['program_title'], 0, 22),
                    $p['final_amount'],
                    $p['discount_amount'],
                    strtoupper($p['status']),
                    $p['confirmed_at'] ?: $p['created_at']
                ),
            ];
        }
        $lines[] = ['bold' => false, 'size' => 9, 'text' => ''];

        $lines[] = ['bold' => true, 'size' => 11, 'text' => '4. SETTLEMENT HISTORY ('.$settlementRows['totals']['count'].' records, Total Amount: PKR '.$settlementRows['totals']['amount'].')'];
        $lines[] = ['bold' => false, 'size' => 9, 'text' => str_repeat('-', 95)];
        foreach ($settlementRows['items'] as $s) {
            $lines[] = [
                'bold' => false,
                'size' => 8,
                'text' => sprintf(
                    '%s | PKR %s | %s | Ref: %s | Date: %s | %s',
                    $s['settlement_reference'],
                    $s['amount'],
                    strtoupper($s['payment_method']),
                    $s['transaction_reference'] ?: '-',
                    $s['paid_at'],
                    strtoupper($s['status'])
                ),
            ];
        }

        return $this->renderSimplePdfDocument($lines);
    }

    private function renderSimplePdfDocument(array $lines): string
    {
        $linesPerPage = 48;
        $pages = array_chunk($lines, $linesPerPage);
        if (empty($pages)) {
            $pages = [[['bold' => false, 'size' => 10, 'text' => 'No records found.']]];
        }

        $objects = [];
        // 1: Catalog, 2: Pages, 3: Font Regular, 4: Font Bold
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
        $objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>';

        $pageObjectIds = [];
        $nextObjId = 5;

        foreach ($pages as $pageIndex => $pageLines) {
            $pageObjId = $nextObjId++;
            $contentObjId = $nextObjId++;
            $pageObjectIds[] = $pageObjId.' 0 R';

            $stream = "BT\n";
            $y = 800;
            foreach ($pageLines as $line) {
                $fontRef = !empty($line['bold']) ? '/F2' : '/F1';
                $size = $line['size'] ?? 9;
                $escapedText = $this->escapePdfText((string) ($line['text'] ?? ''));
                $stream .= sprintf("%s %d Tf\n1 0 0 1 36 %d Tm\n(%s) Tj\n", $fontRef, $size, $y, $escapedText);
                $y -= ($size + 5);
            }
            $footerText = sprintf('Page %d of %d', $pageIndex + 1, count($pages));
            $stream .= sprintf("/F1 8 Tf\n1 0 0 1 500 24 Tm\n(%s) Tj\nET", $this->escapePdfText($footerText));

            $objects[$contentObjId] = sprintf("<< /Length %d >>\nstream\n%s\nendstream", strlen($stream), $stream);
            $objects[$pageObjId] = sprintf(
                '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents %d 0 R >>',
                $contentObjId
            );
        }

        $objects[2] = sprintf('<< /Type /Pages /Kids [%s] /Count %d >>', implode(' ', $pageObjectIds), count($pageObjectIds));
        ksort($objects);

        $pdf = "%PDF-1.4\n";
        $offsets = [0 => 0];
        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($pdf);
            $pdf .= "{$id} 0 obj\n{$body}\nendobj\n";
        }

        $xrefOffset = strlen($pdf);
        $totalObjects = count($objects) + 1;
        $pdf .= "xref\n0 {$totalObjects}\n0000000000 65535 f \n";
        for ($i = 1; $i < $totalObjects; $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        $pdf .= "trailer\n<< /Size {$totalObjects} /Root 1 0 R >>\nstartxref\n{$xrefOffset}\n%%EOF";

        return $pdf;
    }

    private function escapePdfText(string $text): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        if ($ascii === false) {
            $ascii = preg_replace('/[^\x20-\x7E]/', '', $text);
        }
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], (string) $ascii);
    }
}
