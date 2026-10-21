<?php

namespace Tests\Feature;

use App\Enums\BusinessRole;
use App\Models\PlanPaymentRequest;
use App\Support\Plans\PaymentRequestService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\MakesTenants;
use Tests\TestCase;

class PlanPaymentConcurrencySafetyTest extends TestCase
{
    use MakesTenants, RefreshDatabase;

    public function test_database_uniqueness_prevents_duplicate_trx_hash_even_if_service_check_is_bypassed(): void
    {
        $business = $this->makeBusiness();

        $owner = $this->makeMember(
            $business,
            BusinessRole::Owner,
        );

        $service = app(
            PaymentRequestService::class,
        );

        $payment = $service->createUnlimited(
            $business,
            $owner,
            1,
            'RACE000001',
            '01712345678',
        );

        /*
         * Simulate two competing writers where one has already
         * inserted the TrxID and another reaches the database
         * without the normal service duplicate checks.
         *
         * The database UNIQUE constraint must still reject it.
         */
        $duplicate = $payment->replicate();

        $duplicate->uuid = (string) Str::uuid();

        $this->expectException(
            QueryException::class,
        );

        $duplicate->save();
    }
}