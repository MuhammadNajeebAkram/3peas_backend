<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Payment Receipt - The Students Times</title>
</head>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; background-color: #f1f5f9; margin: 0; padding: 25px; color: #1e293b;">
    <table style="max-width: 600px; width: 100%; margin: 0 auto; background-color: #ffffff; border-radius: 8px; overflow: hidden; border: 1px solid #e2e8f0; border-collapse: separate;">
        <!-- Header -->
        <tr style="background-color: #4338ca;">
            <td style="padding: 24px; text-align: center;">
                <h1 style="color: #ffffff; margin: 0; font-size: 22px; font-weight: bold; letter-spacing: -0.5px;">The Students Times</h1>
                <p style="color: #e0e7ff; margin: 4px 0 0 0; font-size: 13px;">Official Payment Receipt &amp; Subscription Confirmation</p>
            </td>
        </tr>

        <!-- Body -->
        <tr>
            <td style="padding: 24px;">
                <p style="font-size: 15px; margin: 0 0 16px 0;">Dear <strong>{{ $student->name ?? 'Valued Student' }}</strong>,</p>
                <p style="font-size: 14px; line-height: 1.6; color: #475569; margin: 0 0 20px 0;">
                    Thank you for your subscription payment. Your payment has been verified and confirmed. Your access to the enrolled course offering is now active.
                </p>

                <!-- Receipt Box -->
                <div style="background-color: #f8fafc; border: 1px solid #cbd5e1; border-radius: 6px; padding: 16px; margin-bottom: 24px;">
                    <table style="width: 100%; border-collapse: collapse; font-size: 13px;">
                        <tr>
                            <td style="color: #64748b; padding: 5px 0;">Receipt Number:</td>
                            <td style="text-align: right; font-weight: bold; color: #4338ca; padding: 5px 0;">{{ $receipt->receipt_number }}</td>
                        </tr>
                        <tr>
                            <td style="color: #64748b; padding: 5px 0;">Course / Program:</td>
                            <td style="text-align: right; font-weight: 600; color: #0f172a; padding: 5px 0;">{{ $offering->title ?? 'LMS Course' }}</td>
                        </tr>
                        <tr>
                            <td style="color: #64748b; padding: 5px 0;">Payment Method:</td>
                            <td style="text-align: right; font-weight: 600; color: #0f172a; padding: 5px 0;">{{ ucwords(str_replace('_', ' ', $receipt->payment_method)) }}</td>
                        </tr>
                        <tr>
                            <td style="color: #64748b; padding: 5px 0;">Channel / Bank:</td>
                            <td style="text-align: right; font-weight: 600; color: #0f172a; padding: 5px 0;">{{ $receipt->payment_channel ?? 'Direct Account' }}</td>
                        </tr>
                        <tr style="border-top: 1px solid #e2e8f0;">
                            <td style="color: #0f172a; font-weight: bold; padding: 10px 0 5px 0; font-size: 14px;">Total Paid:</td>
                            <td style="text-align: right; font-weight: bold; color: #047857; padding: 10px 0 5px 0; font-size: 15px;">{{ number_format((float) $receipt->amount, 2) }} {{ $receipt->currency }}</td>
                        </tr>
                    </table>
                </div>

                <p style="font-size: 13px; line-height: 1.5; color: #64748b; margin: 0 0 20px 0;">
                    A printable PDF copy of your official payment receipt is attached to this email for your records. You may also download it anytime directly from your Student LMS Dashboard.
                </p>

                <!-- CTA -->
                <div style="text-align: center; margin: 25px 0;">
                    <a href="https://thestudentstimes.com/lms/my-classes" style="background-color: #4338ca; color: #ffffff; text-decoration: none; padding: 12px 28px; font-size: 14px; font-weight: bold; border-radius: 6px; display: inline-block;">Go to My LMS Classes</a>
                </div>

                <hr style="border: 0; border-top: 1px solid #e2e8f0; margin: 25px 0;">

                <p style="font-size: 12px; color: #94a3b8; margin: 0; text-align: center;">
                    If you have any questions or require assistance, please reply to this email or contact support at <a href="mailto:support@thestudentstimes.com" style="color: #4338ca; text-decoration: none;">support@thestudentstimes.com</a>.
                </p>
            </td>
        </tr>
    </table>
</body>
</html>
