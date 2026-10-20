<?php

namespace Tests\Feature\Manager;

use App\Models\Customer;
use App\Services\Manager\CustomerIdentifierLookupService;
use App\Support\Security\IdentifierHasher;
use App\Support\Security\IdentifierNormalizer;
use App\Support\Tenancy\CurrentBusiness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;
use Tests\Support\Phase3Fixtures;
use Tests\TestCase;


class CustomerIdentifierLookupServiceTest extends TestCase
{
    use Phase3Fixtures, RefreshDatabase;

    private function service(): CustomerIdentifierLookupService
    {
        return app(CustomerIdentifierLookupService::class);
    }

    private function savedCustomer(array $attributes = []): Customer
    {
        $data = $this->customer($attributes);

        return Customer::withoutGlobalScopes()
            ->where('uuid', $data['id'])
            ->firstOrFail();
    }

    private function sync(Customer $customer): void
    {
        $business = \App\Models\Business::findOrFail($customer->business_id);

        app(CurrentBusiness::class)->runAs(
            $business,
            fn() => DB::transaction(
                fn() => $this->service()->sync($customer)
            )
        );
    }

    private function rows(Customer $customer): array
    {
        return DB::table('customer_identifier_lookups')
            ->where('business_id', $customer->business_id)
            ->where('customer_id', $customer->id)
            ->orderBy('type')
            ->orderBy('identifier_hash')
            ->get()
            ->all();
    }

    public function test_wallet_and_bank_hashes_are_stored(): void
    {
        $this->actAs($this->makeBusiness());

        $customer = $this->savedCustomer([
            'bkash_numbers'  => ['01712345678'],
            'nagad_numbers'  => ['01812345678'],
            'rocket_numbers' => ['017123456789'],
        ]);

        $this->sync($customer);

        $rows = $this->rows($customer);
        $this->assertCount(4, $rows);

        $hasher = app(IdentifierHasher::class);

        $expected = [
            'wallet_bkash'  => $hasher->hash(
                'wallet_bkash',
                IdentifierNormalizer::wallet('01712345678')
            ),
            'wallet_nagad'  => $hasher->hash(
                'wallet_nagad',
                IdentifierNormalizer::wallet('01812345678')
            ),
            'wallet_rocket' => $hasher->hash(
                'wallet_rocket',
                IdentifierNormalizer::wallet('017123456789')
            ),
            'bank_account'  => $hasher->hash(
                'bank_account',
                IdentifierNormalizer::bankAccount('1234567890')
            ),
        ];

        foreach ($rows as $row) {
            $this->assertSame(
                $expected[$row->type],
                $row->identifier_hash
            );

            $this->assertSame(
                (string) $customer->business_id,
                (string) $row->business_id
            );
        }
    }

    public function test_equivalent_wallet_formats_are_deduplicated(): void
    {
        $this->actAs($this->makeBusiness());

        $customer = $this->savedCustomer();

        // Modify the persisted model directly to test the
        // service's canonical deduplication independently
        // of HTTP request validation.
        $customer->bkash_numbers = [
            '+8801712345678',
            '01712345678',
        ];

        $this->sync($customer);

        $count = DB::table('customer_identifier_lookups')
            ->where('customer_id', $customer->id)
            ->where('type', 'wallet_bkash')
            ->count();

        $this->assertSame(1, $count);
    }

    public function test_resynchronization_removes_obsolete_hashes(): void
    {
        $this->actAs($this->makeBusiness());

        $customer = $this->savedCustomer();

        $this->sync($customer);
        $this->assertCount(2, $this->rows($customer));

        $customer->bkash_numbers = [];
        $customer->banks = [];

        $this->sync($customer);

        $this->assertCount(0, $this->rows($customer));
    }

    public function test_sync_requires_a_persisted_customer(): void
    {
        $customer = new Customer();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(
            'Customer must be persisted before syncing identifiers.'
        );

        DB::transaction(function () use ($customer) {
            $this->service()->sync($customer);
        });
    }

    public function test_failed_sync_rolls_back_lookup_changes(): void
    {
        $business = $this->makeBusiness();

        $this->actAs($business);

        $customer = $this->savedCustomer();

        // Establish the initial lookup state.
        $this->sync($customer);

        $before = array_map(
            fn($row) => $row->type . ':' . $row->identifier_hash,
            $this->rows($customer)
        );

        try {
            app(CurrentBusiness::class)->runAs(
                $business,
                function () use ($customer) {
                    DB::transaction(function () use ($customer) {
                        $customer->bkash_numbers = ['01912345678'];

                        $this->service()->sync($customer);

                        throw new RuntimeException('Simulated failure');
                    });
                }
            );

            $this->fail('Expected transaction failure.');
        } catch (RuntimeException $e) {
            $this->assertSame(
                'Simulated failure',
                $e->getMessage()
            );
        }

        $after = array_map(
            fn($row) => $row->type . ':' . $row->identifier_hash,
            $this->rows($customer)
        );

        $this->assertSame($before, $after);
    }

    public function test_lookup_rows_remain_assigned_to_their_business(): void
    {
        $businessA = $this->makeBusiness();
        $businessB = $this->makeBusiness();

        $this->actAs($businessA);
        $customerA = $this->savedCustomer();
        $this->sync($customerA);

        $this->actAs($businessB);
        $customerB = $this->savedCustomer();
        $this->sync($customerB);

        $this->assertSame(
            2,
            DB::table('customer_identifier_lookups')
                ->distinct()
                ->count('business_id')
        );

        foreach ([$customerA, $customerB] as $customer) {
            $this->assertNotEmpty($this->rows($customer));

            foreach ($this->rows($customer) as $row) {
                $this->assertSame(
                    (string) $customer->business_id,
                    (string) $row->business_id
                );
            }
        }
    }

    public function test_cross_business_sync_is_rejected(): void
    {
        $businessA = $this->makeBusiness();
        $businessB = $this->makeBusiness();

        $this->actAs($businessA);
        $customerA = $this->savedCustomer();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(
            'Customer does not belong to the current business.'
        );

        app(CurrentBusiness::class)->runAs(
            $businessB,
            fn() => DB::transaction(
                fn() => $this->service()->sync($customerA)
            )
        );
    }

    public function test_sync_without_business_context_is_rejected(): void
    {
        $business = $this->makeBusiness();

        $this->actAs($business);
        $customer = $this->savedCustomer();

        app(CurrentBusiness::class)->clear();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(
            'Customer does not belong to the current business.'
        );

        DB::transaction(
            fn() => $this->service()->sync($customer)
        );
    }
}
