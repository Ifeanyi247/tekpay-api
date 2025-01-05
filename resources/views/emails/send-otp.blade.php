<!DOCTYPE html>
<html>
<head>
    <title>Registration OTP</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; margin: 0; padding: 20px;">
    <div style="max-width: 600px; margin: 0 auto; background: #fff; padding: 20px;">
        <h2 style="color: #333; text-align: center;">Registration OTP</h2>
        <div style="background: #f9f9f9; padding: 20px; border-radius: 5px; margin: 20px 0;">
            <p style="margin: 0; font-size: 16px;">Your OTP for registration is:</p>
            <h1 style="text-align: center; color: #007bff; margin: 20px 0; font-size: 36px;">{{ $otp }}</h1>
            <p style="margin: 0; font-size: 14px; color: #666;">This OTP will expire in 10 minutes.</p>
        </div>
        <p style="color: #666; font-size: 14px; text-align: center;">If you did not request this OTP, please ignore this email.</p>
    </div>
</body>
</html>
