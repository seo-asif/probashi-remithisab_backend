<?php

namespace Tests\Feature\Reports;

use App\Enums\BusinessRole;
use App\Models\Business;
use App\Models\BusinessSubscription;
use App\Models\SenderSubscription;
use App\Models\ManagerSubscription;
use App\Support\Plans\PlanLimits;
use App\Support\Reports\ManagerReport;
use App\Support\Reports\ReportPeriod;
use App\Support\Reports\SenderReport;
use App\Support\Tenancy\CurrentBusiness;
use App\Support\Time\BusinessClock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Phase3Fixtures;
use Tests\TestCase;

final class PdfExportTest extends TestCase
{
    use Phase3Fixtures, RefreshDatabase;

    public function test_pdf_requires_authentication(): void
    {
        $this->getJson('/api/v1/reports/sender/pdf')
            ->assertUnauthorized();

        $this->getJson('/api/v1/reports/manager/pdf')
            ->assertUnauthorized();
    }

    public function test_free_account_can_download_monthly_pdf(): void
    {
        $business = $this->makeBusiness();
        $this->actAs($business);

        foreach (['sender', 'manager'] as $mode) {
            $response = $this->get(
                "/api/v1/reports/{$mode}/pdf"
            )->assertOk();

            $this->assertStringStartsWith(
                '%PDF-',
                $response->getContent()
            );

            $this->assertStringContainsString(
                'application/pdf',
                $response->headers->get('Content-Type', '')
            );
        }
    }

    public function test_sender_pdf_with_remittance_has_pdf_signature_and_eof(): void
    {
        $business = $this->makeBusiness(['timezone' => 'Pacific/Fiji']);
        $this->actAs($business);
        $this->senderRemittance([
            'amount' => '250',
            'occurred_at' => now()->format('Y-m-d H:i:s'),
        ]);

        $response = $this->get('/api/v1/reports/sender/pdf?period=this_month')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $bytes = $response->getContent();
        $this->assertStringStartsWith('%PDF-', $bytes);
        $this->assertStringContainsString('%%EOF', substr($bytes, -2048));
        $this->assertGreaterThan(300, strlen($bytes));
        $this->assertStringContainsString(
            'attachment;',
            $response->headers->get('Content-Disposition', '')
        );
    }

    public function test_free_account_cannot_export_other_periods(): void
    {
        $business = $this->makeBusiness();
        $this->actAs($business);

        foreach (['sender', 'manager'] as $mode) {
            $this->getJson(
                "/api/v1/reports/{$mode}/pdf?period=all"
            )->assertForbidden();

            $this->getJson(
                "/api/v1/reports/{$mode}/pdf?period=last_month"
            )->assertForbidden();
        }
    }

    public function test_paid_modes_can_export_all_time_pdf(): void
    {
        $business = $this->makeBusiness();
        $this->actAs($business);
        $this->senderPlus();
        $this->managerPro($business);

        foreach (['sender', 'manager'] as $mode) {
            $response = $this->get(
                "/api/v1/reports/{$mode}/pdf?period=all"
            )->assertOk();

            $this->assertStringStartsWith(
                '%PDF-',
                $response->getContent()
            );
        }
    }

    public function test_expired_sender_plus_loses_extended_period_access(): void
    {
        $business = $this->makeBusiness();
        $this->actAs($business);

        $subscription = $this->senderPlus();

        $this->get('/api/v1/reports/sender/pdf?period=all')
            ->assertOk();

        $subscription->forceFill([
            'access_ends_at' => now()->subSecond(),
        ])->save();

        $this->getJson('/api/v1/reports/sender/pdf?period=all')
            ->assertForbidden();
    }

    public function test_invalid_pdf_date_ranges_are_rejected(): void
    {
        $business = $this->makeBusiness();
        $this->actAs($business);
        $this->senderPlus();

        foreach ([
            '?period=custom',
            '?from=2026-09-01',
            '?from=2026-09-30&to=2026-09-01',
            '?period=invalid',
            '?period=this_month&from=2026-09-01&to=2026-09-30',
        ] as $query) {
            $this->getJson(
                '/api/v1/reports/sender/pdf' . $query
            )->assertStatus(422);
        }
    }

    public function test_pdf_has_private_cache_headers(): void
    {
        $business = $this->makeBusiness();
        $this->actAs($business);

        $response = $this->get('/api/v1/reports/sender/pdf')
            ->assertOk();

        $this->assertStringContainsString(
            'no-store',
            $response->headers->get('Cache-Control', '')
        );

        $response->assertHeader(
            'X-Content-Type-Options',
            'nosniff'
        );
    }

    public function test_sender_pdf_summary_excludes_other_users(): void
    {
        $business = $this->makeBusiness([
            'timezone' => 'Pacific/Fiji',
        ]);

        $owner = $this->actAs($business);
        $this->senderRemittance([
            'amount' => '500',
        ]);

        // Another user in the SAME business.
        $this->actAs($business, BusinessRole::Operator);
        $this->senderRemittance([
            'amount' => '900',
        ]);

        $this->login($owner);

        $period = ReportPeriod::fromQuery(
            ['period' => 'all'],
            BusinessClock::for($business)
        );

        $summary = app(CurrentBusiness::class)->runAsUser(
            $business,
            $owner->id,
            fn() => (new SenderReport(
                BusinessClock::for($business)
            ))->totals($period)
        );

        $this->assertSame(1, $summary['times_sent']);
        $this->assertCount(1, $summary['by_currency']);
        $this->assertSame(
            '500.00',
            $summary['by_currency'][0]['total_sent']
        );
    }

    public function test_manager_pdf_summary_excludes_other_businesses(): void
    {
        $business = $this->makeBusiness([
            'timezone' => 'Pacific/Fiji',
        ]);

        $this->actAs($business);

        $customer = $this->customer();
        $this->transaction($customer['id'], [
            'amount' => '500',
        ]);

        // Separate business with its own transaction.
        $other = $this->makeBusiness();
        $this->actAs($other);

        $otherCustomer = $this->customer();
        $this->transaction($otherCustomer['id'], [
            'amount' => '900',
        ]);

        $period = ReportPeriod::fromQuery(
            ['period' => 'all'],
            BusinessClock::for($business)
        );

        $summary = app(CurrentBusiness::class)->runAs(
            $business,
            fn() => (new ManagerReport(
                BusinessClock::for($business)
            ))->totals($period)
        );

        $this->assertCount(1, $summary['by_currency']);
        $this->assertSame(
            1,
            $summary['by_currency'][0]['transactions']
        );
        $this->assertSame(
            '500.00',
            $summary['by_currency'][0]['received']
        );
    }

    public function test_legacy_unlimited_cannot_unlock_extended_pdf_periods(): void
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
        $this->getJson('/api/v1/reports/sender/pdf?period=all')->assertForbidden();
        $this->getJson('/api/v1/reports/manager/pdf?period=all')->assertForbidden();
    }

    public function test_extended_pdf_permissions_are_mode_specific(): void
    {
        $business = $this->makeBusiness();
        $this->actAs($business);
        $this->senderPlus();
        $this->get('/api/v1/reports/sender/pdf?period=all')->assertOk();
        $this->getJson('/api/v1/reports/manager/pdf?period=all')->assertForbidden();
        $this->managerPro($business);
        $this->get('/api/v1/reports/manager/pdf?period=all')->assertOk();
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
