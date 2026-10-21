<?php

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Enums\BusinessRole;
use App\Enums\PlanPaymentProduct;
use App\Enums\PlanPaymentStatus;
use App\Exceptions\ApiException;
use App\Models\Business;
use App\Models\PlanPaymentRequest;
use App\Models\User;
use App\Support\Plans\PaymentRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\MakesTenants;
use Tests\TestCase;

class PaymentRequestServiceTest extends TestCase
{
    use MakesTenants, RefreshDatabase;

    private PaymentRequestService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(PaymentRequestService::class);
    }

    public function test_unlimited_packages_use_server_authoritative_prices(): void
    {
        $packages = [
            1 => 520,
            3 => 1490,
            6 => 2790,
            12 => 5200,
        ];

        foreach ($packages as $months => $price) {
            $business = $this->makeBusiness([
                'name' => "Package {$months}",
            ]);

            $owner = $this->makeMember(
                $business,
                BusinessRole::Owner,
            );

            $request = $this->service->createUnlimited(
                $business,
                $owner,
                $months,
                sprintf('PKG%07d', $months),
                '01712345678',
            );

            $this->assertSame(
                PlanPaymentProduct::Unlimited,
                $request->product_type,
            );

            $this->assertSame(
                $months,
                $request->package_months,
            );

            $this->assertSame(
                $price,
                $request->expected_amount_bdt,
            );

            $this->assertSame(
                PlanPaymentStatus::Pending,
                $request->status,
            );
        }
    }

    public function test_invalid_unlimited_package_is_rejected(): void
    {
        $business = $this->makeBusiness();
        $owner = $this->makeMember($business);

        $this->assertApiException(
            fn () => $this->service->createUnlimited(
                $business,
                $owner,
                2,
                'PACK000001',
                '01712345678',
            ),
            'PLAN_PACKAGE_INVALID',
            422,
        );

        $this->assertDatabaseCount(
            'plan_payment_requests',
            0,
        );
    }

    public function test_payment_identifiers_are_normalized_like_the_prototype(): void
    {
        $this->assertSame(
            '9JK4M7XQ2P',
            $this->service->normalizeTrxId(
                ' 9jk4-m7x q2p ',
            ),
        );

        $this->assertSame(
            '01712345678',
            $this->service->normalizePayerNumber(
                '+880 1712-345678',
            ),
        );

        $this->assertSame(
            '01712345678',
            $this->service->normalizePayerNumber(
                '1712345678',
            ),
        );
    }

    public function test_invalid_trx_id_is_rejected(): void
    {
        $business = $this->makeBusiness();
        $owner = $this->makeMember($business);

        $this->assertApiException(
            fn () => $this->service->createUnlimited(
                $business,
                $owner,
                1,
                'ABC123',
                '01712345678',
            ),
            'PAYMENT_TRX_INVALID',
            422,
        );

        $this->assertDatabaseCount(
            'plan_payment_requests',
            0,
        );
    }

    public function test_invalid_payer_number_is_rejected(): void
    {
        $business = $this->makeBusiness();
        $owner = $this->makeMember($business);

        $this->assertApiException(
            fn () => $this->service->createUnlimited(
                $business,
                $owner,
                1,
                'VALID00001',
                '01212345678',
            ),
            'PAYMENT_PAYER_NUMBER_INVALID',
            422,
        );

        $this->assertDatabaseCount(
            'plan_payment_requests',
            0,
        );
    }

    public function test_owner_and_administrator_can_submit_payment_requests(): void
    {
        $ownerBusiness = $this->makeBusiness([
            'name' => 'Owner Business',
        ]);

        $owner = $this->makeMember(
            $ownerBusiness,
            BusinessRole::Owner,
        );

        $ownerRequest = $this->service->createUnlimited(
            $ownerBusiness,
            $owner,
            1,
            'OWNER00001',
            '01712345678',
        );

        $this->assertSame(
            $owner->id,
            $ownerRequest->submitted_by_user_id,
        );

        $adminBusiness = $this->makeBusiness([
            'name' => 'Admin Business',
        ]);

        $admin = $this->makeMember(
            $adminBusiness,
            BusinessRole::Administrator,
        );

        $adminRequest = $this->service->createUnlimited(
            $adminBusiness,
            $admin,
            3,
            'ADMIN00001',
            '01812345678',
        );

        $this->assertSame(
            $admin->id,
            $adminRequest->submitted_by_user_id,
        );
    }

    public function test_operator_cannot_submit_plan_payment(): void
    {
        $business = $this->makeBusiness();

        $operator = $this->makeMember(
            $business,
            BusinessRole::Operator,
        );

        $this->assertApiException(
            fn () => $this->service->createUnlimited(
                $business,
                $operator,
                1,
                'OPER000001',
                '01712345678',
            ),
            'PLAN_PAYMENT_FORBIDDEN',
            403,
        );

        $this->assertDatabaseCount(
            'plan_payment_requests',
            0,
        );
    }

    public function test_suspended_membership_cannot_submit_plan_payment(): void
    {
        $business = $this->makeBusiness();

        $owner = $this->makeMember(
            $business,
            BusinessRole::Owner,
            [
                'status' => AccountStatus::Suspended,
            ],
        );

        $this->assertApiException(
            fn () => $this->service->createUnlimited(
                $business,
                $owner,
                1,
                'SUSP000001',
                '01712345678',
            ),
            'PLAN_PAYMENT_FORBIDDEN',
            403,
        );

        $this->assertDatabaseCount(
            'plan_payment_requests',
            0,
        );
    }

    public function test_business_cannot_have_more_than_two_pending_requests(): void
    {
        $business = $this->makeBusiness();
        $owner = $this->makeMember($business);

        $this->service->createUnlimited(
            $business,
            $owner,
            1,
            'PAYA000001',
            '01712345678',
        );

        $this->service->createUnlimited(
            $business,
            $owner,
            3,
            'PAYB000002',
            '01712345678',
        );

        $e = $this->assertApiException(
            fn () => $this->service->createUnlimited(
                $business,
                $owner,
                6,
                'PAYC000003',
                '01712345678',
            ),
            'PLAN_PAYMENT_PENDING_LIMIT',
            409,
        );

        $this->assertSame(2, $e->data['limit']);
        $this->assertSame(2, $e->data['pending']);

        $this->assertDatabaseCount(
            'plan_payment_requests',
            2,
        );
    }

    public function test_rejected_request_frees_a_pending_slot(): void
    {
        $business = $this->makeBusiness();
        $owner = $this->makeMember($business);

        $first = $this->service->createUnlimited(
            $business,
            $owner,
            1,
            'PAYA000001',
            '01712345678',
        );

        $this->service->createUnlimited(
            $business,
            $owner,
            3,
            'PAYB000002',
            '01712345678',
        );

        $first->forceFill([
            'status' => PlanPaymentStatus::Rejected,
        ])->save();

        $third = $this->service->createUnlimited(
            $business,
            $owner,
            6,
            'PAYC000003',
            '01712345678',
        );

        $this->assertSame(
            PlanPaymentStatus::Pending,
            $third->status,
        );

        $this->assertDatabaseCount(
            'plan_payment_requests',
            3,
        );
    }

    public function test_approved_request_frees_a_pending_slot(): void
    {
        $business = $this->makeBusiness();
        $owner = $this->makeMember($business);

        $first = $this->service->createUnlimited(
            $business,
            $owner,
            1,
            'PAYA000001',
            '01712345678',
        );

        $this->service->createUnlimited(
            $business,
            $owner,
            3,
            'PAYB000002',
            '01712345678',
        );

        $first->forceFill([
            'status' => PlanPaymentStatus::Approved,
        ])->save();

        $third = $this->service->createUnlimited(
            $business,
            $owner,
            12,
            'PAYC000003',
            '01712345678',
        );

        $this->assertSame(
            PlanPaymentStatus::Pending,
            $third->status,
        );

        $this->assertDatabaseCount(
            'plan_payment_requests',
            3,
        );
    }

    public function test_trx_id_cannot_be_reused_in_same_or_different_business(): void
    {
        $businessA = $this->makeBusiness([
            'name' => 'Business A',
        ]);

        $ownerA = $this->makeMember($businessA);

        $this->service->createUnlimited(
            $businessA,
            $ownerA,
            1,
            'DUPL000001',
            '01712345678',
        );

        $this->assertApiException(
            fn () => $this->service->createUnlimited(
                $businessA,
                $ownerA,
                3,
                'dupl-000001',
                '01712345678',
            ),
            'PAYMENT_TRX_ALREADY_USED',
            409,
        );

        $businessB = $this->makeBusiness([
            'name' => 'Business B',
        ]);

        $ownerB = $this->makeMember($businessB);

        $this->assertApiException(
            fn () => $this->service->createUnlimited(
                $businessB,
                $ownerB,
                6,
                'DUPL000001',
                '01812345678',
            ),
            'PAYMENT_TRX_ALREADY_USED',
            409,
        );

        $this->assertDatabaseCount(
            'plan_payment_requests',
            1,
        );
    }

    public function test_sensitive_payment_identifiers_are_encrypted_at_rest(): void
    {
        $business = $this->makeBusiness();
        $owner = $this->makeMember($business);

        $request = $this->service->createUnlimited(
            $business,
            $owner,
            1,
            'ENCX000001',
            '01712345678',
        );

        $raw = DB::table('plan_payment_requests')
            ->where('id', $request->id)
            ->first();

        $this->assertNotNull($raw);

        /*
         * Raw database values must not contain plaintext.
         */
        $this->assertNotSame(
            'ENCX000001',
            $raw->trx_id,
        );

        $this->assertNotSame(
            '01712345678',
            $raw->payer_number,
        );

        $this->assertStringNotContainsString(
            'ENCX000001',
            $raw->trx_id,
        );

        $this->assertStringNotContainsString(
            '01712345678',
            $raw->payer_number,
        );

        /*
         * Eloquent must transparently decrypt them.
         */
        $fresh = PlanPaymentRequest::query()
            ->findOrFail($request->id);

        $this->assertSame(
            'ENCX000001',
            $fresh->trx_id,
        );

        $this->assertSame(
            '01712345678',
            $fresh->payer_number,
        );

        /*
         * The lookup hash is deterministic, but it must not be the
         * plaintext TrxID.
         */
        $this->assertNotSame(
            'ENCX000001',
            $raw->trx_id_hash,
        );

        $this->assertSame(
            64,
            strlen($raw->trx_id_hash),
        );
    }

    private function assertApiException(
        callable $callback,
        string $code,
        int $status,
    ): ApiException {
        try {
            $callback();
        } catch (ApiException $e) {
            $this->assertSame(
                $code,
                $e->errorCode,
            );

            $this->assertSame(
                $status,
                $e->status,
            );

            return $e;
        }

        $this->fail(
            "Expected ApiException {$code} was not thrown.",
        );
    }
}