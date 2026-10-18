<?php

namespace Tests\Unit\Security;

use App\Models\ManagerTransaction;
use App\Support\Records\MoneyAudit;
use PHPUnit\Framework\TestCase;

class MoneyAuditPrivacyTest extends TestCase
{
    public function test_sensitive_fields_are_excluded_from_audit_values(): void
    {
        $transaction = new ManagerTransaction();

        $transaction->forceFill([
            'currency'       => 'FJD',
            'amount'         => '500.00',
            'rate'           => '52.00',
            'pay_to'         => '01712345678',
            'sending_number' => '01912345678',
        ]);

        $values = MoneyAudit::values($transaction);

        $this->assertSame('500.00', $values['amount']);
        $this->assertSame('52.00', $values['rate']);

        $this->assertSame('01712345678', $values['pay_to']);
        $this->assertSame('01912345678', $values['sending_number']);
    }
}
