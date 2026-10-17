<?php

namespace Tests\Support;

use App\Enums\BusinessRole;
use App\Models\AuditLog;
use App\Models\Business;
use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/** Shared helpers for the Phase 3 tests: log in as a member and create records through the real API. */
trait Phase3Fixtures
{
    use MakesTenants;

    protected function actAs(Business $business, BusinessRole $role = BusinessRole::Owner): User
    {
        $user = $this->makeMember($business, $role);
        Sanctum::actingAs($user);

        return $user;
    }

    protected function login(User $user): void
    {
        Sanctum::actingAs($user);
    }

    protected function uuid(): string
    {
        return (string) Str::uuid7();
    }

    protected function customer(array $o = []): array
    {
        return $this->postJson('/api/v1/customers', [
            'name'          => 'Rahim Uddin',
            'phone'         => '+679 912 3456',
            'bkash_numbers' => ['01712345678'],
            'banks'         => [['account_name' => 'Karim Uddin', 'account_number' => '1234567890', 'bank_name' => 'Sonali Bank', 'branch' => 'Sylhet', 'routing_number' => '123456789']],
            ...$o,
        ])->assertCreated()->json('data');
    }

    protected function txPayload(string $customerId, array $o = []): array
    {
        return [
            'customer_id'    => $customerId,
            'currency'       => 'FJD',
            'amount'         => '500',
            'rate'           => '52',
            'receive_method' => 'Bkash',
            'pay_to'         => '01712345678',
            'status'         => 'currency_received',
            'occurred_at'    => '2026-09-15 10:00',
            ...$o,
        ];
    }

    protected function transaction(string $customerId, array $o = []): array
    {
        return $this->postJson('/api/v1/manager-transactions', $this->txPayload($customerId, $o))->assertCreated()->json('data');
    }

    protected function pay(string $txId, string $amount, array $o = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson("/api/v1/manager-transactions/{$txId}/payments", ['amount_bdt' => $amount, 'paid_on' => '2026-09-16', ...$o]);
    }

    protected function remittance(array $o = []): array
    {
        return $this->postJson('/api/v1/manager-remittances', [
            'method' => 'Bank', 'currency' => 'FJD', 'amount' => '1000', 'rate' => '51.55', 'charge' => '0', 'charge_type' => 'excluded',
            'incentive' => true, 'incentive_pct' => '2.5', 'occurred_at' => '2026-09-16 09:00', ...$o,
        ])->assertCreated()->json('data');
    }

    protected function recipient(array $o = []): array
    {
        return $this->postJson('/api/v1/sender-recipients', ['name' => 'মা', 'relationship' => 'Mother', 'phone' => '01811111111', ...$o])->assertCreated()->json('data');
    }

    protected function senderPayload(array $o = []): array
    {
        return [
            'recipient_type' => 'self', 'currency' => 'FJD', 'amount' => '500', 'rate' => '50', 'method' => 'Bank',
            'charge_type' => 'excluded', 'charge' => '10', 'incentive_pct' => '2.5', 'occurred_at' => '2026-09-20 18:30', ...$o,
        ];
    }

    protected function senderRemittance(array $o = []): array
    {
        return $this->postJson('/api/v1/sender-remittances', $this->senderPayload($o))->assertCreated()->json('data');
    }

    protected function audits(string $action): int
    {
        return AuditLog::query()->where('action', $action)->count();
    }
}
