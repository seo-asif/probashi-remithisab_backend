<?php

namespace Tests\Feature\Reports;

use App\Enums\BusinessRole;
use App\Models\Business;
use App\Models\BusinessSubscription;
use App\Models\SenderSubscription;
use App\Models\ManagerSubscription;
use App\Models\SenderRemittance;
use App\Support\Plans\PlanLimits;
use App\Support\Tenancy\CurrentBusiness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Phase3Fixtures;
use Tests\TestCase;

final class CsvExportTest extends TestCase
{
    use Phase3Fixtures, RefreshDatabase;

    public function test_free_account_cannot_export_csv(): void
    {
        $this->actAs($this->makeBusiness());
        $this->getJson('/api/v1/reports/sender/csv')->assertForbidden();
        $this->getJson('/api/v1/reports/manager/csv')->assertForbidden();
    }

    public function test_sender_plus_downloads_csv_and_expiry_blocks_it(): void
    {
        $business = $this->makeBusiness();
        $this->actAs($business);
        $subscription = $this->senderPlus();
        $response = $this->get('/api/v1/reports/sender/csv?period=all')->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->assertStringStartsWith("\xEF\xBB\xBF", $response->streamedContent());
        $subscription->forceFill(['access_ends_at' => now()->subSecond()])->save();
        $this->getJson('/api/v1/reports/sender/csv')->assertForbidden();
    }

    public function test_sender_export_is_private_and_excludes_deleted_records(): void
    {
        $business = $this->makeBusiness(['timezone' => 'Pacific/Fiji']);
        $owner = $this->actAs($business);
        $this->senderPlus();
        $this->senderRemittance(['occurred_at' => '2026-09-20 18:30']);
        $this->postJson('/api/v1/student/money-in', [
            'from_id' => '__other__', 'from_name' => 'Father',
            'amount_bdt' => '4500.00', 'method' => 'Bank', 'received_on' => '2026-09-19',
        ])->assertCreated();
        $deleted = $this->senderRemittance(['amount' => '99', 'occurred_at' => '2026-09-18 10:00']);
        $this->deleteJson('/api/v1/sender-remittances/' . $deleted['id'])->assertOk();
        $this->actAs($business, BusinessRole::Operator);
        $this->senderRemittance(['amount' => '77', 'occurred_at' => '2026-09-17 10:00']);
        $this->login($owner);

        $csv = $this->get('/api/v1/reports/sender/csv?period=all')->assertOk()->streamedContent();
        $this->assertStringContainsString('Remittance,2026-09-20', $csv);
        $this->assertStringContainsString('"Money received",2026-09-19,Father,BDT,4500.00', $csv);
        $this->assertStringNotContainsString(',99.00,', $csv);
        $this->assertStringNotContainsString(',77.00,', $csv);
        $this->assertStringContainsString("\r\n", $csv);
    }

    public function test_manager_datasets_and_business_isolation(): void
    {
        $business = $this->makeBusiness(['timezone' => 'Pacific/Fiji']);
        $firstOwner = $this->actAs($business);
        $this->managerPro($business);
        $customer = $this->customer();
        $this->transaction($customer['id'], ['occurred_at' => '2026-09-15 10:00']);
        $this->remittance(['occurred_at' => '2026-09-16 10:00']);
        $other = $this->makeBusiness();
        $this->actAs($other);
        $this->managerPro($other);
        $otherCustomer = $this->customer(['name' => 'Other Tenant']);
        $this->transaction($otherCustomer['id'], ['amount' => '333']);
        $this->login($firstOwner);
        $tx = $this->get('/api/v1/reports/manager/csv?dataset=transactions&period=all')->assertOk()->streamedContent();
        $this->assertStringContainsString('Rahim Uddin', $tx);
        $this->assertStringNotContainsString('Other Tenant', $tx);
        $this->assertStringNotContainsString('01712345678', $tx);
        $rem = $this->get('/api/v1/reports/manager/csv?dataset=remittances&period=all')->assertOk()->streamedContent();
        $this->assertStringContainsString('Remitted BDT', $rem);
        $this->assertStringContainsString('2026-09-16,FJD,1000.00', $rem);
    }

    public function test_bangla_headings_and_formula_escaping(): void
    {
        $business = $this->makeBusiness();
        $user = $this->actAs($business);
        $this->senderPlus();
        $this->patchJson('/api/v1/account/settings', ['language' => 'bn'])->assertOk();
        $sent = $this->senderRemittance();
        app(CurrentBusiness::class)->runAsUser($business, $user->id, function () use ($sent): void {
            SenderRemittance::findByUuidOrFail($sent['id'])
                ->forceFill(['notes' => '=HYPERLINK("https://example.test", "click")'])->save();
        });
        $csv = $this->get('/api/v1/reports/sender/csv?period=all')->assertOk()->streamedContent();
        $this->assertStringContainsString('ধরন,তারিখ,নাম,মুদ্রা', $csv);
        $this->assertStringContainsString("'=HYPERLINK", $csv);
    }

    public function test_invalid_dataset_and_date_ranges_are_rejected(): void
    {
        $business = $this->makeBusiness();
        $this->actAs($business);
        $this->senderPlus();
        $this->managerPro($business);
        foreach ([
            '/api/v1/reports/sender/csv?dataset=fees',
            '/api/v1/reports/manager/csv?dataset=money_received',
            '/api/v1/reports/sender/csv?period=custom',
            '/api/v1/reports/sender/csv?from=2026-09-01',
            '/api/v1/reports/sender/csv?from=2026-09-30&to=2026-09-01',
        ] as $url) {
            $this->getJson($url)->assertStatus(422);
        }
    }

    public function test_csv_requires_authentication(): void
    {
        $this->getJson('/api/v1/reports/sender/csv')->assertUnauthorized();
        $this->getJson('/api/v1/reports/manager/csv')->assertUnauthorized();
    }

    public function test_legacy_unlimited_does_not_unlock_either_csv_mode(): void
    {
        $business = $this->makeBusiness();
        $this->actAs($business);
        BusinessSubscription::create([
            'business_id' => $business->id,
            'tier' => PlanLimits::UNLIMITED,
            'access_source' => 'manual',
            'access_starts_at' => now()->subDay(),
            'access_ends_at' => now()->addDay(),
        ]);
        $this->getJson('/api/v1/reports/sender/csv')->assertForbidden();
        $this->getJson('/api/v1/reports/manager/csv')->assertForbidden();
    }

    public function test_paid_csv_permissions_are_mode_specific(): void
    {
        $business = $this->makeBusiness();
        $this->actAs($business);
        $this->senderPlus();
        $this->get('/api/v1/reports/sender/csv')->assertOk();
        $this->getJson('/api/v1/reports/manager/csv')->assertForbidden();
        $this->managerPro($business);
        $this->get('/api/v1/reports/manager/csv')->assertOk();
    }

    private function senderPlus(): SenderSubscription
    {
        return SenderSubscription::create([
            'user_id' => auth()->id(),
            'tier' => PlanLimits::SENDER_PLUS,
            'access_source' => 'manual',
            'access_starts_at' => now()->subDay(),
            'access_ends_at' => now()->addDay(),
        ]);
    }

    private function managerPro(Business $business): ManagerSubscription
    {
        return ManagerSubscription::create([
            'business_id' => $business->id,
            'tier' => PlanLimits::MANAGER_PRO,
            'access_source' => 'manual',
            'access_starts_at' => now()->subDay(),
            'access_ends_at' => now()->addDay(),
        ]);
    }
}
