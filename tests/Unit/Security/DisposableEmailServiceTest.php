<?php

namespace Tests\Unit\Security;

use App\Support\Auth\DisposableEmailService;
use Tests\TestCase;

class DisposableEmailServiceTest extends TestCase
{
    public function test_known_disposable_domains_are_blocked(): void
    {
        $this->assertTrue(
            DisposableEmailService::isDisposable(
                'test@mailinator.com'
            )
        );

        $this->assertTrue(
            DisposableEmailService::isDisposable(
                'test@guerrillamail.com'
            )
        );

        $this->assertTrue(
            DisposableEmailService::isDisposable(
                'test@sub.mailinator.com'
            )
        );
    }

    public function test_normal_email_domains_are_allowed(): void
    {
        $this->assertFalse(
            DisposableEmailService::isDisposable(
                'test@gmail.com'
            )
        );

        $this->assertFalse(
            DisposableEmailService::isDisposable(
                'test@outlook.com'
            )
        );

        $this->assertFalse(
            DisposableEmailService::isDisposable(
                'test@yahoo.com'
            )
        );
    }

    public function test_similar_domain_names_are_not_blocked(): void
    {
        $this->assertFalse(
            DisposableEmailService::isDisposable(
                'test@notmailinator.com'
            )
        );
    }
}
