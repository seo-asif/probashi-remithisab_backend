<?php

namespace App\Mail;

use App\Models\OtpCode;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class OtpMail extends Mailable
{
    public function __construct(
        public string $recipientName,
        public string $code,
        public string $purpose,
        public int $ttlMinutes,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->purpose === OtpCode::PURPOSE_PASSWORD_RESET
                ? 'Your password reset code'
                : 'Your verification code',
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.otp');
    }
}
