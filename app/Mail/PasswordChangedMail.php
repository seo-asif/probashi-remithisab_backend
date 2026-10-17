<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class PasswordChangedMail extends Mailable
{
    public function __construct(public string $recipientName)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your password was changed');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.password-changed');
    }
}
