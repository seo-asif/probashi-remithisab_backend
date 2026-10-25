<?php

namespace Tests\Feature\Reports;

use App\Enums\BusinessRole;
use App\Models\Business;
use App\Models\BusinessSubscription;
use App\Services\Student\StudentService;
use App\Support\Plans\PlanLimits;
use App\Support\Tenancy\CurrentBusiness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Phase3Fixtures;
use Tests\TestCase;

final class StudentPdfExportTest extends TestCase
{
    use Phase3Fixtures, RefreshDatabase;

    private const URL = '/api/v1/reports/student/pdf';

    public function test_student_pdf_requires_authentication(): void
    {
        $this->getJson(self::URL)->assertUnauthorized();
    }

    public function test_free_monthly_student_pdf_is_downloadable_and_private(): void
    {
        $business = $this->makeBusiness();
        $this->actAs($business);

        Storage::fake('local');
        Storage::fake('public');

        $response = $this->get(self::URL)->assertOk();
        $this->assertStringStartsWith('%PDF-', $response->getContent());
        $this->assertStringContainsString('application/pdf', $response->headers->get('Content-Type', ''));
        $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition', ''));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control', ''));
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_free_plan_rejects_extended_periods(): void
    {
        $business = $this->makeBusiness();
        $this->actAs($business);

        foreach (['all', 'last_month', 'this_year'] as $period) {
            $this->getJson(self::URL . '?period=' . $period)->assertForbidden();
        }

        $this->getJson(self::URL . '?from=2026-09-01&to=2026-09-30')->assertForbidden();
    }

    public function test_unlimited_plan_accepts_custom_period_and_validates_dates(): void
    {
        $business = $this->makeBusiness();
        $this->actAs($business);
        $this->unlimited($business);

        $response = $this->get(self::URL . '?from=2026-09-01&to=2026-09-30')->assertOk();
        $this->assertStringStartsWith('%PDF-', $response->getContent());

        foreach ([
            '?period=custom',
            '?from=2026-09-01',
            '?from=2026-09-30&to=2026-09-01',
            '?period=invalid',
            '?period=this_month&from=2026-09-01&to=2026-09-30',
        ] as $query) {
            $this->getJson(self::URL . $query)->assertStatus(422);
        }
    }

    public function test_expired_unlimited_reverts_to_monthly_only(): void
    {
        $business = $this->makeBusiness();
        $this->actAs($business);
        $subscription = $this->unlimited($business);

        $this->get(self::URL . '?period=all')->assertOk();

        $subscription->forceFill(['access_ends_at' => now()->subSecond()])->save();
        $this->getJson(self::URL . '?period=all')->assertForbidden();
        $this->get(self::URL)->assertOk();
    }

    public function test_student_financial_summary_is_user_private_and_currency_separated(): void
    {
        $business = $this->makeBusiness();
        $owner = $this->actAs($business);

        $this->putJson('/api/v1/student/opening-balances', [
            'currency' => 'FJD', 'amount' => '100.00',
        ])->assertSuccessful();

        $this->postJson('/api/v1/student/money-in', [
            'from_id' => '__other__', 'from_name' => 'Family',
            'amount_bdt' => '1000.00', 'method' => 'Bank', 'received_on' => '2026-09-15',
        ])->assertCreated();

        $this->postJson('/api/v1/student/expenses', [
            'category' => 'Food', 'amount' => '25.00',
            'currency' => 'FJD', 'spent_on' => '2026-09-16',
        ])->assertCreated();

        $this->actAs($business, BusinessRole::Operator);
        $this->postJson('/api/v1/student/expenses', [
            'category' => 'Food', 'amount' => '900.00',
            'currency' => 'FJD', 'spent_on' => '2026-09-16',
        ])->assertCreated();

        $summary = app(CurrentBusiness::class)->runAsUser(
            $business,
            (int) $owner->id,
            fn () => app(StudentService::class)->summary('2026-09-01', '2026-09-30'),
        );

        $rows = collect($summary['by_currency'])->keyBy('currency');
        $this->assertSame('1000.00', $rows['BDT']['period_received']);
        $this->assertSame('100.00', $rows['FJD']['opening_balance']);
        $this->assertSame('25.00', $rows['FJD']['period_spent']);
        $this->assertSame('75.00', $rows['FJD']['remaining_as_of_to']);
        $this->assertSame('25.00', $rows['FJD']['expense_categories']['Food']);
        $this->assertCount(2, $rows);

        $this->login($owner);
        $this->unlimited($business);
        $response = $this->get(self::URL . '?from=2026-09-01&to=2026-09-30')->assertOk();
        $this->assertStringStartsWith('%PDF-', $response->getContent());
    }

    private function unlimited(Business $business): BusinessSubscription
    {
        return BusinessSubscription::create([
            'business_id' => $business->id,
            'tier' => PlanLimits::UNLIMITED,
            'access_source' => 'manual',
            'access_starts_at' => now()->subDay(),
            'access_ends_at' => now()->addDay(),
        ]);
    }
}
