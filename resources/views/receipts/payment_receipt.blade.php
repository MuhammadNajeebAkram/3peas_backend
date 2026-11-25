<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Payment Receipt - {{ $receipt->receipt_number }}</title>
    <style>
        @page {
            margin: 25mm 20mm 20mm 20mm;
            size: A4 portrait;
        }
        body {
            font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
            font-size: 13px;
            line-height: 1.5;
            color: #1e293b;
            margin: 0;
            padding: 0;
        }
        .header-table {
            width: 100%;
            border-bottom: 2px solid #6366f1;
            padding-bottom: 18px;
            margin-bottom: 25px;
        }
        .logo-text {
            font-size: 24px;
            font-weight: 800;
            color: #4338ca;
            letter-spacing: -0.5px;
            margin: 0;
        }
        .logo-sub {
            font-size: 11px;
            color: #64748b;
            margin-top: 2px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .receipt-badge {
            background-color: #ecfdf5;
            border: 1px solid #10b981;
            color: #047857;
            font-size: 12px;
            font-weight: bold;
            padding: 4px 12px;
            border-radius: 12px;
            display: inline-block;
            text-transform: uppercase;
        }
        .receipt-meta {
            text-align: right;
        }
        .receipt-title {
            font-size: 20px;
            font-weight: bold;
            color: #0f172a;
            margin: 0 0 4px 0;
        }
        .receipt-num {
            font-size: 13px;
            font-weight: 600;
            color: #4f46e5;
            margin-bottom: 4px;
        }
        .info-table {
            width: 100%;
            margin-bottom: 25px;
        }
        .info-col {
            width: 50%;
            vertical-align: top;
        }
        .section-heading {
            font-size: 11px;
            font-weight: 700;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            margin-bottom: 8px;
            border-bottom: 1px solid #e2e8f0;
            padding-bottom: 4px;
        }
        .data-row {
            margin-bottom: 4px;
        }
        .data-label {
            color: #64748b;
            font-size: 12px;
            width: 110px;
            display: inline-block;
        }
        .data-value {
            font-weight: 600;
            color: #1e293b;
            font-size: 12px;
        }
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 25px;
        }
        .items-table th {
            background-color: #f8fafc;
            color: #475569;
            font-weight: 700;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-top: 1px solid #cbd5e1;
            border-bottom: 1px solid #cbd5e1;
            padding: 10px 12px;
            text-align: left;
        }
        .items-table td {
            padding: 12px;
            border-bottom: 1px solid #e2e8f0;
            vertical-align: top;
        }
        .totals-table {
            width: 100%;
            margin-bottom: 30px;
        }
        .totals-col-left {
            width: 55%;
            vertical-align: top;
        }
        .totals-col-right {
            width: 45%;
            vertical-align: top;
        }
        .calc-table {
            width: 100%;
            border-collapse: collapse;
        }
        .calc-table td {
            padding: 6px 12px;
        }
        .calc-table .total-row {
            border-top: 2px solid #6366f1;
            border-bottom: 2px solid #6366f1;
            font-size: 15px;
            font-weight: 800;
            color: #4338ca;
            background-color: #f5f3ff;
        }
        .notice-box {
            background-color: #f8fafc;
            border-left: 3px solid #6366f1;
            padding: 12px 14px;
            border-radius: 0 4px 4px 0;
            font-size: 11px;
            color: #475569;
            line-height: 1.4;
        }
        .footer-table {
            width: 100%;
            border-top: 1px solid #e2e8f0;
            padding-top: 15px;
            margin-top: 30px;
            font-size: 11px;
            color: #94a3b8;
        }
    </style>
</head>
<body>

    <!-- Header Section -->
    <table class="header-table" cellpadding="0" cellspacing="0">
        <tr>
            <td style="vertical-align: middle;">
                <div class="logo-text">The Students Times</div>
                <div class="logo-sub">Official Online Learning & Examination Platform</div>
            </td>
            <td class="receipt-meta" style="vertical-align: middle;">
                <div class="receipt-title">PAYMENT RECEIPT</div>
                <div class="receipt-num">{{ $receipt->receipt_number }}</div>
                <div><span class="receipt-badge">&#10003; PAID &amp; CONFIRMED</span></div>
            </td>
        </tr>
    </table>

    <!-- Student & Transaction Info -->
    <table class="info-table" cellpadding="0" cellspacing="0">
        <tr>
            <td class="info-col" style="padding-right: 15px;">
                <div class="section-heading">Student Details</div>
                <div class="data-row">
                    <span class="data-label">Full Name:</span>
                    <span class="data-value">{{ $student->name ?? 'N/A' }}</span>
                </div>
                <div class="data-row">
                    <span class="data-label">Email:</span>
                    <span class="data-value">{{ $student->email ?? 'N/A' }}</span>
                </div>
                <div class="data-row">
                    <span class="data-label">Phone:</span>
                    <span class="data-value">{{ $student->phone ?? ($payment->payer_phone ?? 'N/A') }}</span>
                </div>
                <div class="data-row">
                    <span class="data-label">Student ID:</span>
                    <span class="data-value">STU-{{ str_pad($student->id ?? 0, 5, '0', STR_PAD_LEFT) }}</span>
                </div>
            </td>
            <td class="info-col" style="padding-left: 15px;">
                <div class="section-heading">Receipt &amp; Payment Summary</div>
                <div class="data-row">
                    <span class="data-label">Receipt Date:</span>
                    <span class="data-value">{{ $receipt->issued_at ? $receipt->issued_at->format('d M Y, h:i A') : now()->format('d M Y') }}</span>
                </div>
                <div class="data-row">
                    <span class="data-label">Payment Method:</span>
                    <span class="data-value">{{ ucwords(str_replace('_', ' ', $receipt->payment_method)) }}</span>
                </div>
                <div class="data-row">
                    <span class="data-label">Channel / Account:</span>
                    <span class="data-value">{{ $receipt->payment_channel ?? 'Direct' }}</span>
                </div>
                <div class="data-row">
                    <span class="data-label">Transaction Ref:</span>
                    <span class="data-value">{{ $receipt->transaction_reference ?: ($payment->transaction_id ?: 'Verified Direct') }}</span>
                </div>
            </td>
        </tr>
    </table>

    <!-- Subscribed Items Table -->
    <table class="items-table" cellpadding="0" cellspacing="0">
        <thead>
            <tr>
                <th style="width: 50%;">Description / Subscription Offering</th>
                <th style="width: 20%; text-align: center;">Validity Period</th>
                <th style="width: 15%; text-align: right;">List Price</th>
                <th style="width: 15%; text-align: right;">Final Amount</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>
                    <strong style="color: #0f172a; font-size: 13px;">{{ $offering->title ?? 'LMS Course Subscription' }}</strong>
                    <div style="font-size: 11px; color: #64748b; margin-top: 3px;">
                        Class: {{ $offering->offeredClass?->userClass?->class_name ?? 'Standard Class' }} &bull; Access to Solved Past Papers, MCQs, Workshops &amp; Test Sessions
                    </div>
                </td>
                <td style="text-align: center; color: #475569; font-size: 11px;">
                    {{ $offering->offeredClass?->session_start ? \Carbon\Carbon::parse($offering->offeredClass->session_start)->format('d M Y') : 'Immediate' }}
                    <br>&ndash; to &ndash;<br>
                    {{ $offering->offeredClass?->session_end ? \Carbon\Carbon::parse($offering->offeredClass->session_end)->format('d M Y') : 'Session End' }}
                </td>
                <td style="text-align: right; color: #64748b;">
                    {{ number_format((float) $payment->price, 2) }} {{ $receipt->currency }}
                </td>
                <td style="text-align: right; font-weight: 700; color: #0f172a;">
                    {{ number_format((float) $receipt->amount, 2) }} {{ $receipt->currency }}
                </td>
            </tr>
        </tbody>
    </table>

    <!-- Totals and Remarks -->
    <table class="totals-table" cellpadding="0" cellspacing="0">
        <tr>
            <td class="totals-col-left" style="padding-right: 20px;">
                <div class="notice-box">
                    <strong>Payment Verification Note:</strong><br>
                    This subscription payment has been authenticated and recorded in the TST financial accounting ledger. Your student portal access has been granted for the specified validity period.
                    <br><br>
                    <strong>Support:</strong> support@thestudentstimes.com &bull; 0321-8490137
                </div>
            </td>
            <td class="totals-col-right">
                <table class="calc-table" cellpadding="0" cellspacing="0">
                    <tr>
                        <td style="color: #64748b;">Subtotal:</td>
                        <td style="text-align: right; font-weight: 600;">{{ number_format((float) $payment->price, 2) }} {{ $receipt->currency }}</td>
                    </tr>
                    @if((float) $payment->discount_amount > 0)
                    <tr>
                        <td style="color: #059669;">Special Discount:</td>
                        <td style="text-align: right; font-weight: 600; color: #059669;">- {{ number_format((float) $payment->discount_amount, 2) }} {{ $receipt->currency }}</td>
                    </tr>
                    @endif
                    <tr class="total-row">
                        <td>Amount Paid:</td>
                        <td style="text-align: right;">{{ number_format((float) $receipt->amount, 2) }} {{ $receipt->currency }}</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    <!-- Footer -->
    <table class="footer-table" cellpadding="0" cellspacing="0">
        <tr>
            <td style="text-align: left;">
                This is a computer-generated receipt and requires no physical signature.<br>
                Verification Reference: {{ $receipt->receipt_number }} &bull; Transaction: {{ $transaction->transaction_number ?? 'TXN-CONFIRMED' }}
            </td>
            <td style="text-align: right; vertical-align: top;">
                The Students Times (Pvt) Ltd<br>
                https://thestudentstimes.com
            </td>
        </tr>
    </table>

</body>
</html>
