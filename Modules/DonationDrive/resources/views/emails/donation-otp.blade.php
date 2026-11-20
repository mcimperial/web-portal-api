<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Giving Together verification code</title>
</head>
<body style="margin:0;padding:0;background:#f4fbfb;font-family:Arial,Helvetica,sans-serif;color:#334155;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f4fbfb;padding:32px 12px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:620px;background:#ffffff;border:1px solid #ccfbf1;border-radius:24px;overflow:hidden;box-shadow:0 18px 45px rgba(15,118,110,.12);">
                    <tr>
                        <td style="padding:32px;background:linear-gradient(135deg,#0f766e,#0d9488 60%,#0ea5e9);color:#ffffff;text-align:center;">
                            <div style="font-size:34px;line-height:1;">&#9829;</div>
                            <p style="margin:14px 0 4px;font-size:12px;font-weight:700;letter-spacing:2px;text-transform:uppercase;color:#ccfbf1;">Employee giving</p>
                            <h1 style="margin:0;font-size:28px;line-height:1.25;">Giving Together</h1>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:36px 32px;">
                            <p style="margin:0 0 8px;font-size:14px;font-weight:700;color:#0f766e;text-transform:uppercase;letter-spacing:1px;">Secure donation portal sign-in</p>
                            <h2 style="margin:0 0 18px;font-size:24px;color:#0f172a;">Hello{{ $name ? ' '.$name : '' }},</h2>
                            <p style="margin:0 0 24px;font-size:16px;line-height:1.7;color:#475569;">
                                Use this one-time password to securely access your Giving Together donation account.
                            </p>
                            <div style="padding:26px 16px;border:2px dashed #5eead4;border-radius:18px;background:#f0fdfa;text-align:center;">
                                <span style="font-family:'Courier New',monospace;font-size:38px;font-weight:700;letter-spacing:10px;color:#0f766e;">{{ $otp }}</span>
                            </div>
                            <p style="margin:20px 0 26px;text-align:center;font-size:14px;color:#64748b;">
                                This code expires in <strong style="color:#0f766e;">5 minutes</strong>.
                            </p>
                            <div style="padding:16px 18px;border-radius:12px;background:#f8fafc;border:1px solid #e2e8f0;">
                                <p style="margin:0;font-size:14px;line-height:1.6;color:#475569;">
                                    <strong style="color:#0f172a;">Keep it private.</strong> Giving Together will never ask you to share this code. If you did not request it, you can safely ignore this message.
                                </p>
                            </div>
                            <p style="margin:28px 0 0;font-size:14px;line-height:1.7;color:#64748b;">
                                Thank you for helping our generosity reach farther.<br>
                                <strong style="color:#0f766e;">The Giving Together team</strong>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:22px 28px;background:#f8fafc;border-top:1px solid #e2e8f0;text-align:center;">
                            <p style="margin:0;font-size:12px;line-height:1.6;color:#94a3b8;">
                                Automated donation portal message &bull; Please do not reply<br>
                                &copy; {{ date('Y') }} LLIBI Giving Together
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
