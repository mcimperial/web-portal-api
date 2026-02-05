<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email Verification - LLIBI</title>
</head>
<body style="margin: 0; padding: 0; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background-color: #f4f4f4;">
    <table role="presentation" style="width: 100%; border-collapse: collapse;">
        <tr>
            <td align="center" style="padding: 40px 0;">
                <table role="presentation" style="width: 600px; border-collapse: collapse; background-color: #ffffff; border-radius: 8px; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);">
                    <!-- Header with Logo -->
                    <tr>
                        <td style="padding: 40px 40px 20px 40px; text-align: center; background: linear-gradient(135deg, #1e3a5f 0%, #2d5a87 100%); border-radius: 8px 8px 0 0;">
                            <img src="https://llibi.app/llibi-logo-white.png" alt="LLIBI Logo" style="height: 60px; width: auto;" onerror="this.style.display='none'">
                            <h1 style="color: #ffffff; margin: 20px 0 0 0; font-size: 24px; font-weight: 600;">LLIBI</h1>
                            <p style="color: #b8d4e8; margin: 5px 0 0 0; font-size: 14px;">Lacson & Lacson Insurance Brokers, Inc.</p>
                        </td>
                    </tr>
                    
                    <!-- Content -->
                    <tr>
                        <td style="padding: 40px;">
                            <h2 style="color: #1e3a5f; margin: 0 0 20px 0; font-size: 22px; font-weight: 600;">Email Verification</h2>
                            
                            <p style="color: #555555; font-size: 16px; line-height: 1.6; margin: 0 0 20px 0;">
                                Hello <strong>{{ $name }}</strong>,
                            </p>
                            
                            <p style="color: #555555; font-size: 16px; line-height: 1.6; margin: 0 0 30px 0;">
                                Thank you for registering with LLIBI. Please use the following One-Time Password (OTP) to verify your email address:
                            </p>
                            
                            <!-- OTP Box -->
                            <table role="presentation" style="width: 100%; border-collapse: collapse; margin: 0 0 30px 0;">
                                <tr>
                                    <td align="center">
                                        <div style="background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%); border: 2px dashed #1e3a5f; border-radius: 12px; padding: 25px 40px; display: inline-block;">
                                            <span style="font-size: 36px; font-weight: 700; letter-spacing: 12px; color: #1e3a5f; font-family: 'Courier New', monospace;">{{ $otp }}</span>
                                        </div>
                                    </td>
                                </tr>
                            </table>
                            
                            <p style="color: #888888; font-size: 14px; line-height: 1.6; margin: 0 0 20px 0; text-align: center;">
                                <strong>This code will expire in {{ $expiresInMinutes }} minutes.</strong>
                            </p>
                            
                            <div style="background-color: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; border-radius: 4px; margin: 0 0 30px 0;">
                                <p style="color: #856404; font-size: 14px; margin: 0;">
                                    <strong>Security Notice:</strong> If you did not request this verification code, please ignore this email. Do not share this code with anyone.
                                </p>
                            </div>
                            
                            <p style="color: #555555; font-size: 16px; line-height: 1.6; margin: 0;">
                                Best regards,<br>
                                <strong style="color: #1e3a5f;">The LLIBI Team</strong>
                            </p>
                        </td>
                    </tr>
                    
                    <!-- Footer -->
                    <tr>
                        <td style="padding: 30px 40px; background-color: #f8f9fa; border-radius: 0 0 8px 8px; border-top: 1px solid #e9ecef;">
                            <table role="presentation" style="width: 100%; border-collapse: collapse;">
                                <tr>
                                    <td style="text-align: center;">
                                        <p style="color: #888888; font-size: 12px; margin: 0 0 10px 0;">
                                            © {{ date('Y') }} Lacson & Lacson Insurance Brokers, Inc. All rights reserved.
                                        </p>
                                        <p style="color: #888888; font-size: 12px; margin: 0;">
                                            This is an automated message. Please do not reply to this email.
                                        </p>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
