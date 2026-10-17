<!doctype html>
<html>
<body style="font-family: Arial, Helvetica, sans-serif; color: #17202a; line-height: 1.5;">
  <p>Hello {{ $recipientName }},</p>

  <p>
    @if($purpose === \App\Models\OtpCode::PURPOSE_PASSWORD_RESET)
      Your password reset code is:
    @else
      Your verification code is:
    @endif
  </p>

  <p style="font-size: 32px; font-weight: bold; letter-spacing: 6px; margin: 12px 0;">{{ $code }}</p>

  <p>This code works for {{ $ttlMinutes }} minutes. Do not share it with anyone, not even with us.</p>
  <p>এই কোডটি {{ $ttlMinutes }} মিনিট কাজ করবে। কোডটি কাউকে বলবেন না।</p>

  <p style="color: #66727f; font-size: 14px;">If you did not ask for this code, you can ignore this email.</p>
</body>
</html>
