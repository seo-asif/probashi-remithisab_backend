<?php

namespace Tests\Unit\Security;

use App\Models\AuditLog;
use App\Services\Auth\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthAuditMetadataTest extends TestCase
{
    use RefreshDatabase;

    public function test_failed_login_preserves_valid_email_hash(): void
    {
        $hash = hash('sha256', 'example@example.com');

        (new AuditLogger())->record(
            'auth.login_failed',
            null,
            null,
            null,
            ['email_hash' => $hash]
        );

        $log = AuditLog::query()
            ->where('action', 'auth.login_failed')
            ->firstOrFail();

        $this->assertSame(
            ['email_hash' => $hash],
            $log->metadata
        );
    }

    public function test_failed_login_discards_unapproved_metadata(): void
    {
        $hash = hash('sha256', 'example@example.com');

        (new AuditLogger())->record(
            'auth.login_failed',
            null,
            null,
            null,
            [
                'email_hash' => $hash,
                'password' => 'PRIVATE_PASSWORD',
                'otp' => 'PRIVATE_OTP',
                'access_token' => 'PRIVATE_TOKEN',
                'details' => [
                    'secret' => 'PRIVATE_NESTED_DATA',
                ],
            ]
        );

        $log = AuditLog::query()
            ->where('action', 'auth.login_failed')
            ->firstOrFail();

        $this->assertSame(
            ['email_hash' => $hash],
            $log->metadata
        );
    }

    public function test_invalid_email_hash_is_not_persisted(): void
    {
        (new AuditLogger())->record(
            'auth.login_failed',
            null,
            null,
            null,
            ['email_hash' => 'PRIVATE_EMAIL_ADDRESS']
        );

        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'auth.login_failed',
        ]);
    }

    public function test_other_auth_events_discard_metadata(): void
    {
        (new AuditLogger())->record(
            'auth.password_changed',
            null,
            null,
            null,
            [
                'password' => 'PRIVATE_PASSWORD',
                'reset_token' => 'PRIVATE_RESET_TOKEN',
            ]
        );

        $log = AuditLog::query()
            ->where('action', 'auth.password_changed')
            ->firstOrFail();

        $this->assertNull($log->metadata);
    }

    public function test_unknown_auth_event_discards_metadata(): void
    {
        (new AuditLogger())->record(
            'auth.future_event',
            null,
            null,
            null,
            ['secret' => 'PRIVATE_INFORMATION']
        );

        $log = AuditLog::query()
            ->where('action', 'auth.future_event')
            ->firstOrFail();

        $this->assertNull($log->metadata);
    }
}
