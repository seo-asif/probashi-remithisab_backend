<?php

namespace App\Jobs;

use App\Mail\OtpMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Mail;

/**
 * Sends one OTP email. It implements ShouldBeEncrypted, so the code is encrypted inside the queue
 * table and never sits there as readable text.
 */
class SendOtpEmail implements ShouldQueue, ShouldBeEncrypted
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public function __construct(
        public string $email,
        public string $recipientName,
        public string $purpose,
        public string $code,
        public int $ttlMinutes,
    ) {
    }

    /** @return int[] seconds to wait before each retry */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(): void
    {
        // The log and array mailers would write the code in readable text: fine on a developer machine only.
        if (app()->isProduction() && in_array(config('mail.default'), ['log', 'array'], true)) {
            throw new \RuntimeException('Email is not configured for production: set MAIL_MAILER and the SMTP details.');
        }

        Mail::to($this->email)->send(new OtpMail($this->recipientName, $this->code, $this->purpose, $this->ttlMinutes));
    }
}
