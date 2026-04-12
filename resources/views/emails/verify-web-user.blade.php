<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Your Email</title>
</head>
<body style="margin:0;padding:24px;background-color:#f5f7fb;font-family:Arial,sans-serif;color:#1f2937;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:640px;border-collapse:collapse;background:#ffffff;border-radius:12px;overflow:hidden;">
                    <tr>
                        <td style="padding:32px;">
                            <h1 style="margin:0 0 16px;font-size:24px;line-height:1.3;">Welcome to {{ $appName }}!</h1>
                            <p style="margin:0 0 16px;font-size:16px;line-height:1.6;">
                                Click the button below to verify your email address and activate your account.
                            </p>
                            <p style="margin:24px 0;">
                                <a href="{{ $verificationUrl }}" style="display:inline-block;padding:12px 24px;background-color:#0f766e;color:#ffffff;text-decoration:none;border-radius:8px;font-size:16px;">
                                    Verify Email
                                </a>
                            </p>
                            <p style="margin:0 0 12px;font-size:14px;line-height:1.6;color:#4b5563;">
                                If the button does not work, copy and paste this link into your browser:
                            </p>
                            <p style="margin:0 0 16px;font-size:14px;line-height:1.6;word-break:break-all;">
                                <a href="{{ $verificationUrl }}" style="color:#0f766e;">{{ $verificationUrl }}</a>
                            </p>
                            <p style="margin:0;font-size:14px;line-height:1.6;color:#4b5563;">
                                If you did not register on our website, please ignore this email.
                            </p>
                            <p style="margin:24px 0 0;font-size:14px;line-height:1.6;">
                                Best Regards,<br>
                                {{ $appName }}
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
