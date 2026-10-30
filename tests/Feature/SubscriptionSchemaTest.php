<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SubscriptionSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_subscription_tables_have_expected_columns(): void
    {
        foreach ([
            'sender_subscriptions' => 'user_id',
            'manager_subscriptions' => 'business_id',
        ] as $table => $ownerColumn) {
            $this->assertTrue(Schema::hasTable($table));

            $this->assertTrue(Schema::hasColumns($table, [
                'id',
                $ownerColumn,
                'tier',
                'access_source',
                'access_starts_at',
                'access_ends_at',
                'created_at',
                'updated_at',
            ]));
        }
    }

    public function test_subscription_owners_have_unique_indexes(): void
    {
        foreach ([
            'sender_subscriptions' => 'user_id',
            'manager_subscriptions' => 'business_id',
        ] as $table => $ownerColumn) {
            $found = false;

            foreach (Schema::getIndexes($table) as $index) {
                if (
                    $index['unique']
                    && $index['columns'] === [$ownerColumn]
                ) {
                    $found = true;
                    break;
                }
            }

            $this->assertTrue(
                $found,
                "Missing unique owner index on {$table}."
            );
        }
    }

    public function test_subscription_owners_have_foreign_keys(): void
    {
        foreach ([
            'sender_subscriptions' => ['user_id', 'users'],
            'manager_subscriptions' => ['business_id', 'businesses'],
        ] as $table => [$column, $referencedTable]) {
            $found = false;

            foreach (Schema::getForeignKeys($table) as $foreignKey) {
                if (
                    $foreignKey['columns'] === [$column]
                    && $foreignKey['foreign_table'] === $referencedTable
                    && strtolower($foreignKey['on_delete']) === 'cascade'
                ) {
                    $found = true;
                    break;
                }
            }

            $this->assertTrue(
                $found,
                "Missing cascading foreign key on {$table}."
            );
        }
    }

    public function test_duplicate_sender_subscription_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->insertSenderSubscription($user->id);

        $this->expectException(QueryException::class);

        $this->insertSenderSubscription($user->id);
    }

    public function test_duplicate_manager_subscription_is_rejected(): void
    {
        $business = Business::factory()->create();

        $this->insertManagerSubscription($business->id);

        $this->expectException(QueryException::class);

        $this->insertManagerSubscription($business->id);
    }

    public function test_different_users_can_have_sender_subscriptions(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();

        $this->insertSenderSubscription($first->id);
        $this->insertSenderSubscription($second->id);

        $this->assertSame(
            2,
            DB::table('sender_subscriptions')->count()
        );
    }

    public function test_different_businesses_can_have_manager_subscriptions(): void
    {
        $first = Business::factory()->create();
        $second = Business::factory()->create();

        $this->insertManagerSubscription($first->id);
        $this->insertManagerSubscription($second->id);

        $this->assertSame(
            2,
            DB::table('manager_subscriptions')->count()
        );
    }

    public function test_sender_subscription_requires_existing_user(): void
    {
        $this->expectException(QueryException::class);

        $this->insertSenderSubscription(999999999);
    }

    public function test_manager_subscription_requires_existing_business(): void
    {
        $this->expectException(QueryException::class);

        $this->insertManagerSubscription(999999999);
    }

    public function test_deleting_user_cascades_sender_subscription(): void
    {
        $user = User::factory()->create();

        $this->insertSenderSubscription($user->id);

        $this->assertSame(
            1,
            DB::table('sender_subscriptions')->count()
        );

        $user->delete();

        $this->assertSame(
            0,
            DB::table('sender_subscriptions')->count()
        );
    }

    public function test_deleting_business_cascades_manager_subscription(): void
    {
        $business = Business::factory()->create();

        $this->insertManagerSubscription($business->id);

        $this->assertSame(
            1,
            DB::table('manager_subscriptions')->count()
        );

        $business->delete();

        $this->assertSame(
            0,
            DB::table('manager_subscriptions')->count()
        );
    }

    private function insertSenderSubscription(int $userId): void
    {
        DB::table('sender_subscriptions')->insert([
            'user_id' => $userId,
            'tier' => 'plus',
            'access_source' => 'paid',
            'access_starts_at' => now(),
            'access_ends_at' => now()->addMonth(),
        ]);
    }

    private function insertManagerSubscription(int $businessId): void
    {
        DB::table('manager_subscriptions')->insert([
            'business_id' => $businessId,
            'tier' => 'pro',
            'access_source' => 'paid',
            'access_starts_at' => now(),
            'access_ends_at' => now()->addMonth(),
        ]);
    }
}
