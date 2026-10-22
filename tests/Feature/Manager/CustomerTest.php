<?php

namespace Tests\Feature\Manager;

use App\Models\Customer;
use App\Support\Security\IdentifierHasher;
use App\Support\Security\IdentifierNormalizer;
use App\Support\Tenancy\CurrentBusiness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\Phase3Fixtures;
use Tests\TestCase;

class CustomerTest extends TestCase
{
    use Phase3Fixtures, RefreshDatabase;

    public function test_a_customer_is_created_with_cleaned_values_and_a_public_id(): void
    {
        $this->actAs($this->makeBusiness());

        $data = $this->customer(['name' => '  Rahim Uddin ', 'phone' => '+880 1712-345678', 'bkash_numbers' => ['017 1234 5678']]);

        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $data['id']);
        $this->assertSame('Rahim Uddin', $data['name']);
        $this->assertSame('+8801712345678', $data['phone']);
        $this->assertSame(['01712345678'], $data['bkash_numbers']);
        $this->assertSame('1234567890', $data['banks'][0]['account_number']);
     $this->assertNull(Customer::withoutGlobalScopes()->first()->phone_key);
    }

    public function test_fields_are_validated_like_the_app(): void
    {
        $this->actAs($this->makeBusiness());

        $response = $this->postJson('/api/v1/customers', [
            'name'           => 'R',
            'phone'          => 'abc',
            'address'        => '<script>x</script>',
            'bkash_numbers'  => ['0171234567', '01712345678', '01712345678'],
            'rocket_numbers' => ['017123456789'],
            'banks'          => [['account_name' => 'K', 'account_number' => '12ab', 'bank_name' => '', 'routing_number' => '123']],
        ])->assertStatus(422)->assertJsonPath('code', 'VALIDATION_FAILED');

        $errors = $response->json('errors');
        $this->assertSame('Customer name must be at least 2 characters.', $errors['name'][0]);
        $this->assertSame('name_too_short', $response->json('error_codes.name.0'));
        $this->assertSame('wallet_number_invalid', $response->json('error_codes')['bkash_numbers.0'][0]);
        $this->assertSame('Use numbers only, with an optional + at the beginning, up to 18 digits.', $errors['phone'][0]);
        $this->assertSame('Use plain text with letters, numbers, spaces, , . - / # and ( ).', $errors['address'][0]);
        $this->assertSame('bKash number starting with 01 must be 11 digits.', $errors['bkash_numbers.0'][0]);
        $this->assertSame('This number is already added for this customer.', $errors['bkash_numbers.1'][0]);
        $this->assertSame('Account number must contain numbers only.', $errors['banks.0.account_number'][0]);
        $this->assertSame('Bank name is required.', $errors['banks.0.bank_name'][0]);
        $this->assertSame('Routing number must be exactly 9 digits.', $errors['banks.0.routing_number'][0]);
        $this->assertArrayNotHasKey('rocket_numbers.0', $errors); // Rocket allows 12 digits
    }

    public function test_more_than_five_numbers_of_one_wallet_are_refused(): void
    {
        $this->actAs($this->makeBusiness());

        $this->postJson('/api/v1/customers', [
            'name'          => 'Rahim',
            'nagad_numbers' => ['01711111111', '01711111112', '01711111113', '01711111114', '01711111115', '01711111116'],
        ])->assertStatus(422)->assertJsonPath('errors.nagad_numbers.0', 'Up to 5 Nagad numbers can be added for one customer.');
    }

    public function test_an_active_customer_with_the_same_number_is_refused_whatever_the_format(): void
    {
        $this->actAs($this->makeBusiness());
        $first = $this->customer(['phone' => '+8801712345678']);

        $this->postJson('/api/v1/customers', ['name' => 'Someone Else', 'phone' => '01712-345678'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'DUPLICATE_CUSTOMER')
            ->assertJsonPath('data.customer.id', $first['id'])
            ->assertJsonPath('message', 'A customer with this contact number already exists: Rahim Uddin');

        $this->assertSame(1, Customer::withoutGlobalScopes()->count());
    }

    public function test_a_recently_deleted_customer_is_offered_back_and_restored_with_the_same_id(): void
    {
        $this->actAs($this->makeBusiness());

        $first = $this->customer([
            'phone' => '01712345678',
        ]);

        $this->deleteJson("/api/v1/customers/{$first['id']}")
            ->assertOk()
            ->assertJsonPath('message', 'Customer deleted.');

        $this->getJson('/api/v1/customers')
            ->assertJsonPath('data.meta.total', 0);

        $this->getJson('/api/v1/customers?trashed=only')
            ->assertJsonPath('data.items.0.id', $first['id']);

        // Cancel in the app = nothing more is sent: no replacement is created
        $this->postJson('/api/v1/customers', [
            'name'  => 'New Name',
            'phone' => '+8801712345678',
        ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'RESTORE_AVAILABLE')
            ->assertJsonPath('data.customer.id', $first['id'])
            ->assertJsonPath('data.customer.name', 'Rahim Uddin');

        $this->assertSame(
            1,
            Customer::withoutGlobalScopes()->withTrashed()->count()
        );

        $this->postJson("/api/v1/customers/{$first['id']}/restore")
            ->assertOk()
            ->assertJsonPath('data.id', $first['id']);

        $this->postJson("/api/v1/customers/{$first['id']}/restore")
            ->assertOk(); // twice is harmless

        $this->getJson('/api/v1/customers')
            ->assertJsonPath('data.meta.total', 1);
    }

    public function test_after_thirty_days_a_new_customer_can_use_the_number(): void
    {
        $this->actAs($this->makeBusiness());
        $first = $this->customer(['phone' => '01712345678']);
        $this->deleteJson("/api/v1/customers/{$first['id']}")->assertOk();

        Customer::withoutGlobalScopes()->withTrashed()->update(['deleted_at' => now()->subDays(31)]);

        $this->postJson('/api/v1/customers', ['name' => 'New Person', 'phone' => '01712345678'])->assertCreated();
    }

    public function test_restoring_is_refused_when_an_active_customer_took_the_number(): void
    {
        $this->actAs($this->makeBusiness());
        $first = $this->customer(['phone' => '01712345678']);
        $this->deleteJson("/api/v1/customers/{$first['id']}")->assertOk();
        Customer::withoutGlobalScopes()->withTrashed()->update(['deleted_at' => now()->subDays(40)]);
        $second = $this->customer(['name' => 'Second', 'phone' => '01712345678']);

        $this->postJson("/api/v1/customers/{$first['id']}/restore")
            ->assertStatus(409)->assertJsonPath('code', 'DUPLICATE_CUSTOMER')->assertJsonPath('data.customer.id', $second['id']);
    }

    public function test_customer_with_transactions_can_be_archived_without_losing_history(): void
    {
        $this->actAs($this->makeBusiness());
        $customer = $this->customer();
        $this->transaction($customer['id']);

        $this->deleteJson("/api/v1/customers/{$customer['id']}")
            ->assertOk();

        $this->getJson("/api/v1/customers/{$customer['id']}")
            ->assertOk()
            ->assertJsonPath('data.summary.transactions', 1);

        $this->postJson("/api/v1/customers/{$customer['id']}/restore")
            ->assertOk();

        $this->getJson("/api/v1/customers/{$customer['id']}")
            ->assertOk()
            ->assertJsonPath('data.summary.transactions', 1);
    }

    public function test_sending_the_same_create_twice_saves_one_customer(): void
    {
        $this->actAs($this->makeBusiness());
        $uuid = $this->uuid();

        $this->postJson('/api/v1/customers', ['id' => $uuid, 'name' => 'Rahim', 'phone' => '0171'])->assertCreated()->assertJsonPath('data.id', $uuid);
        $this->postJson('/api/v1/customers', ['id' => $uuid, 'name' => 'Rahim', 'phone' => '0171'])
            ->assertOk()->assertJsonPath('message', 'Already saved.')->assertJsonPath('data.id', $uuid);

        $this->assertSame(1, Customer::withoutGlobalScopes()->count());
    }

    public function test_update_checks_the_number_and_refuses_a_stale_offline_edit(): void
    {
        $this->actAs($this->makeBusiness());
        $a = $this->customer(['phone' => '0171']);
        $b = $this->customer(['name' => 'Other', 'phone' => '0172']);

        $this->patchJson("/api/v1/customers/{$b['id']}", ['phone' => '0171'])->assertStatus(409)->assertJsonPath('code', 'DUPLICATE_CUSTOMER');

        $this->patchJson("/api/v1/customers/{$a['id']}", ['notes' => 'Pays on Fridays', 'base_updated_at' => $a['updated_at']])
            ->assertOk()->assertJsonPath('data.notes', 'Pays on Fridays')->assertJsonPath('data.phone', '0171');

        $this->travel(2)->seconds();
        $this->patchJson("/api/v1/customers/{$a['id']}", ['notes' => 'x']); // another phone changed it
        $this->patchJson("/api/v1/customers/{$a['id']}", ['notes' => 'old copy', 'base_updated_at' => $a['updated_at']])
            ->assertStatus(409)->assertJsonPath('code', 'EDIT_CONFLICT')->assertJsonPath('data.current.notes', 'x');
    }

    public function test_search_finds_names_and_exact_identifiers(): void
    {
        $this->actAs($this->makeBusiness());

        $this->customer([
            'name'           => 'Rahim',
            'phone'          => '+8801712345678',
            'address'        => 'Nadi Town',
            'bkash_numbers'  => ['01799999999'],
            'nagad_numbers'  => ['01888888888'],
            'rocket_numbers' => ['019777777777'],
            'banks'          => [[
                'bank_name'      => 'Sonali Bank',
                'account_name'   => 'Rahim Uddin',
                'account_number' => '1234567890',
            ]],
        ]);

        $this->customer([
            'name'           => 'Karim',
            'phone'          => '0155',
            'address'        => 'Lautoka',
            'bkash_numbers'  => [],
            'nagad_numbers'  => [],
            'rocket_numbers' => [],
            'banks'          => [],
        ]);

        // Non-sensitive text fields still support partial search.
        $this->getJson('/api/v1/customers?q=rah')
            ->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('data.items.0.name', 'Rahim');

        $this->getJson('/api/v1/customers?q=nadi')
            ->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('data.items.0.name', 'Rahim');

        // Primary phone is exact-match through phone_hash.
        $this->getJson('/api/v1/customers?q=01712345678')
            ->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('data.items.0.name', 'Rahim');

        // Equivalent normalized international form resolves to the same hash.
        $this->getJson('/api/v1/customers?q=%2B8801712345678')
            ->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('data.items.0.name', 'Rahim');

        // Saved sensitive identifiers use exact HMAC lookup.
        $this->getJson('/api/v1/customers?q=01799999999')
            ->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('data.items.0.name', 'Rahim');

        $this->getJson('/api/v1/customers?q=01888888888')
            ->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('data.items.0.name', 'Rahim');

        $this->getJson('/api/v1/customers?q=019777777777')
            ->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('data.items.0.name', 'Rahim');

        $this->getJson('/api/v1/customers?q=1234567890')
            ->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('data.items.0.name', 'Rahim');

        // Partial sensitive identifiers must no longer match.
        $this->getJson('/api/v1/customers?q=01712345')
            ->assertJsonPath('data.meta.total', 0);

        $this->getJson('/api/v1/customers?q=99999')
            ->assertJsonPath('data.meta.total', 0);

        $this->getJson('/api/v1/customers?q=456789')
            ->assertJsonPath('data.meta.total', 0);

        // LIKE wildcard characters remain literal for normal text search.
        $this->getJson('/api/v1/customers?q=%25')
            ->assertJsonPath('data.meta.total', 0);
    }

    public function test_another_business_cannot_see_use_or_discover_customers(): void
    {
        $a = $this->makeBusiness();
        $b = $this->makeBusiness();

        $this->actAs($a);
        $customer = $this->customer(['phone' => '01712345678']);

        $this->actAs($b);
        $this->getJson('/api/v1/customers')->assertJsonPath('data.meta.total', 0);
        foreach (['get', 'patch', 'delete'] as $verb) {
            $this->json($verb, "/api/v1/customers/{$customer['id']}", ['name' => 'Hacked'])->assertStatus(404)->assertJsonPath('code', 'NOT_FOUND');
        }
        $this->postJson("/api/v1/customers/{$customer['id']}/restore")->assertStatus(404);

        // the same number is free in another business: nothing about business A is revealed
        $this->postJson('/api/v1/customers', ['name' => 'Rahim B', 'phone' => '01712345678'])->assertCreated();

        // and B cannot use A's customer in a transaction
        $this->postJson('/api/v1/manager-transactions', [
            'customer_id' => $customer['id'], 'amount' => '10', 'rate' => '50', 'receive_method' => 'Cash',
            'status'      => 'bdt_sent', 'occurred_at' => '2026-09-15 10:00',
        ])->assertStatus(422)->assertJsonPath('error_codes.customer_id.0', 'customer_required');

        app(CurrentBusiness::class)->runAs($a, fn() => $this->assertSame('Rahim Uddin', Customer::first()->name));
    }

    public function test_customer_detail_has_totals_per_currency(): void
    {
        $this->actAs($this->makeBusiness());
        $customer = $this->customer();
        $this->transaction($customer['id'], ['amount' => '100', 'rate' => '54.25', 'status' => 'bdt_sent']);
        $this->transaction($customer['id'], ['amount' => '50', 'rate' => '54', 'status' => 'currency_received', 'receive_method' => 'Cash']);
        $this->transaction($customer['id'], ['currency' => 'AUD', 'amount' => '10', 'rate' => '80', 'status' => 'currency_pending', 'receive_method' => 'Cash']);

        $this->getJson("/api/v1/customers/{$customer['id']}")
            ->assertOk()
            ->assertJsonPath('data.summary.transactions', 3)
            ->assertJsonPath('data.summary.by_currency.0.currency', 'AUD')
            ->assertJsonPath('data.summary.by_currency.0.obligation_bdt', '0.00')
            ->assertJsonPath('data.summary.by_currency.1.amount', '150.00')
            ->assertJsonPath('data.summary.by_currency.1.paid_bdt', '5425.00')
            ->assertJsonPath('data.summary.by_currency.1.owed_bdt', '2700.00');
    }

    public function test_customer_create_synchronizes_identifier_lookups(): void
    {
        $this->actAs($this->makeBusiness());

        $data = $this->customer([
            'phone'          => '+8801712345678',
            'bkash_numbers'  => ['01712345678'],
            'nagad_numbers'  => ['01812345678'],
            'rocket_numbers' => ['017123456789'],
        ]);

        $customer = Customer::withoutGlobalScopes()
            ->where('uuid', $data['id'])
            ->firstOrFail();

        $rows = DB::table('customer_identifier_lookups')
            ->where('business_id', $customer->business_id)
            ->where('customer_id', $customer->id)
            ->get();

        // Fixture also contains one bank account.
        $this->assertCount(4, $rows);

        $hasher = app(IdentifierHasher::class);

        $this->assertTrue(
            $rows->contains(
                fn($row) =>
                $row->type === 'wallet_bkash'
                && $row->identifier_hash === $hasher->hash(
                    'wallet_bkash',
                    IdentifierNormalizer::wallet('01712345678')
                )
            )
        );

        $this->assertTrue(
            $rows->contains(
                fn($row) =>
                $row->type === 'bank_account'
                && $row->identifier_hash === $hasher->hash(
                    'bank_account',
                    IdentifierNormalizer::bankAccount('1234567890')
                )
            )
        );
    }

    public function test_customer_update_resynchronizes_identifier_lookups(): void
    {
        $this->actAs($this->makeBusiness());

        $data = $this->customer([
            'bkash_numbers' => ['01712345678'],
        ]);

        $customer = Customer::withoutGlobalScopes()
            ->where('uuid', $data['id'])
            ->firstOrFail();

        $this->patchJson("/api/v1/customers/{$data['id']}", [
            'bkash_numbers' => ['01912345678'],
        ])->assertOk();

        $hasher = app(IdentifierHasher::class);

        $oldHash = $hasher->hash(
            'wallet_bkash',
            IdentifierNormalizer::wallet('01712345678')
        );

        $newHash = $hasher->hash(
            'wallet_bkash',
            IdentifierNormalizer::wallet('01912345678')
        );

        $this->assertFalse(
            DB::table('customer_identifier_lookups')
                ->where('business_id', $customer->business_id)
                ->where('customer_id', $customer->id)
                ->where('type', 'wallet_bkash')
                ->where('identifier_hash', $oldHash)
                ->exists()
        );

        $this->assertTrue(
            DB::table('customer_identifier_lookups')
                ->where('business_id', $customer->business_id)
                ->where('customer_id', $customer->id)
                ->where('type', 'wallet_bkash')
                ->where('identifier_hash', $newHash)
                ->exists()
        );
    }

    public function test_customer_restore_resynchronizes_missing_identifier_lookups(): void
    {
        $this->actAs($this->makeBusiness());

        $data = $this->customer([
            'bkash_numbers' => ['01712345678'],
        ]);

        $customer = Customer::withoutGlobalScopes()
            ->where('uuid', $data['id'])
            ->firstOrFail();

        $this->deleteJson("/api/v1/customers/{$data['id']}")
            ->assertOk();

        // Simulate lookup loss while the customer is soft deleted.
        DB::table('customer_identifier_lookups')
            ->where('business_id', $customer->business_id)
            ->where('customer_id', $customer->id)
            ->delete();

        $this->assertSame(
            0,
            DB::table('customer_identifier_lookups')
                ->where('business_id', $customer->business_id)
                ->where('customer_id', $customer->id)
                ->count()
        );

        $this->postJson("/api/v1/customers/{$data['id']}/restore")
            ->assertOk();

        $this->assertGreaterThan(
            0,
            DB::table('customer_identifier_lookups')
                ->where('business_id', $customer->business_id)
                ->where('customer_id', $customer->id)
                ->count()
        );
    }

    public function test_customer_phone_hash_is_stored_from_normalized_phone(): void
    {
        $this->actAs($this->makeBusiness());

        $data = $this->customer([
            'phone' => '+8801712345678',
        ]);

        $customer = Customer::withoutGlobalScopes()
            ->where('uuid', $data['id'])
            ->firstOrFail();

        $expected = app(IdentifierHasher::class)->hash(
            'customer_phone',
            IdentifierNormalizer::customerPhone('01712345678')
        );

        $this->assertSame($expected, $customer->phone_hash);
        $this->assertSame(64, strlen($customer->phone_hash));
    }

    public function test_equivalent_customer_phone_formats_have_the_same_hash(): void
    {
        $hasher = app(IdentifierHasher::class);

        $first = $hasher->hash(
            'customer_phone',
            IdentifierNormalizer::customerPhone('+8801712345678')
        );

        $second = $hasher->hash(
            'customer_phone',
            IdentifierNormalizer::customerPhone('01712345678')
        );

        $this->assertSame($first, $second);
    }

    public function test_updating_customer_phone_updates_phone_hash(): void
    {
        $this->actAs($this->makeBusiness());

        $data = $this->customer([
            'phone' => '01712345678',
        ]);

        $customer = Customer::withoutGlobalScopes()
            ->where('uuid', $data['id'])
            ->firstOrFail();

        $oldHash = $customer->phone_hash;

        $this->patchJson("/api/v1/customers/{$data['id']}", [
            'phone' => '01912345678',
        ])->assertOk();

        $customer->refresh();

        $expected = app(IdentifierHasher::class)->hash(
            'customer_phone',
            IdentifierNormalizer::customerPhone('01912345678')
        );

        $this->assertNotSame($oldHash, $customer->phone_hash);
        $this->assertSame($expected, $customer->phone_hash);
    }
}
