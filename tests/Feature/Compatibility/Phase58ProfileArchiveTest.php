<?php

namespace Tests\Feature\Compatibility;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Phase3Fixtures;
use Tests\TestCase;

class Phase58ProfileArchiveTest extends TestCase
{
    use Phase3Fixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actAs($this->makeBusiness());
    }

    public function test_family_archive_is_separate_from_delete_and_restore_is_repeatable(): void
    {
        $family = $this->postJson('/api/v1/sender-recipients', [
            'name' => 'Amma',
        ])->assertCreated()->json('data');

        $this->postJson("/api/v1/sender-recipients/{$family['id']}/archive")
            ->assertOk()->assertJsonPath('data.archived', true);
        $this->postJson("/api/v1/sender-recipients/{$family['id']}/archive")
            ->assertOk()->assertJsonPath('data.archived', true);

        $this->getJson('/api/v1/sender-recipients')->assertJsonPath('data.meta.total', 0);
        $this->getJson('/api/v1/sender-recipients?archived=only')
            ->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('data.items.0.id', $family['id']);
        $this->getJson("/api/v1/sender-recipients/{$family['id']}")
            ->assertOk()->assertJsonPath('data.archived', true);

        $this->getJson('/api/v1/me')->assertJsonPath('data.plan.allowances.family_members.used', 1);
        $this->postJson("/api/v1/sender-recipients/{$family['id']}/unarchive")
            ->assertOk()->assertJsonPath('data.archived', false)
            ->assertJsonPath('data.archived_at', null);
        $this->postJson("/api/v1/sender-recipients/{$family['id']}/unarchive")->assertOk();
        $this->getJson('/api/v1/sender-recipients')->assertJsonPath('data.meta.total', 1);
    }

    public function test_customer_archive_hides_from_list_without_freeing_quota(): void
    {
        $customer = $this->postJson('/api/v1/customers', ['name' => 'Rahim'])
            ->assertCreated()->json('data');
        $this->postJson("/api/v1/customers/{$customer['id']}/archive")
            ->assertOk()->assertJsonPath('data.archived', true);
        $this->getJson('/api/v1/customers')->assertJsonPath('data.meta.total', 0);
        $this->getJson('/api/v1/customers?archived=only')
            ->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('data.items.0.id', $customer['id']);
        $this->getJson('/api/v1/me')->assertJsonPath('data.plan.allowances.customers.used', 1);
        $this->postJson("/api/v1/customers/{$customer['id']}/unarchive")
            ->assertOk()->assertJsonPath('data.archived', false);
        $this->getJson('/api/v1/customers')->assertJsonPath('data.meta.total', 1);
    }

    public function test_new_unused_profiles_can_be_deleted_permanently_and_release_lifetime_slots(): void
    {
        $family = $this->postJson('/api/v1/sender-recipients', ['name' => 'New Family'])
            ->assertCreated()->json('data');
        $customer = $this->postJson('/api/v1/customers', ['name' => 'New Customer'])
            ->assertCreated()->json('data');

        $this->deleteJson("/api/v1/sender-recipients/{$family['id']}/delete-new")->assertOk();
        $this->deleteJson("/api/v1/customers/{$customer['id']}/delete-new")->assertOk();

        $this->getJson('/api/v1/me')->assertOk()
            ->assertJsonPath('data.plan.allowances.family_members.used', 0)
            ->assertJsonPath('data.plan.allowances.customers.used', 0);
        $this->getJson("/api/v1/sender-recipients/{$family['id']}")->assertNotFound();
        $this->getJson("/api/v1/customers/{$customer['id']}")->assertNotFound();

        $this->postJson('/api/v1/sender-recipients', ['name' => 'Another Family'])->assertCreated();
        $this->postJson('/api/v1/customers', ['name' => 'Another Customer'])->assertCreated();
    }

    public function test_permanent_delete_is_refused_after_ten_minutes_even_without_records(): void
    {
        $family = $this->postJson('/api/v1/sender-recipients', ['name' => 'Old Family'])
            ->assertCreated()->json('data');
        // Move the application clock forward rather than rewriting a model timestamp.
        // This tests the real elapsed-time rule regardless of DB timestamp precision.
        $this->travel(11)->minutes();

        $this->deleteJson("/api/v1/sender-recipients/{$family['id']}/delete-new")
            ->assertStatus(409)->assertJsonPath('code', 'PROFILE_DELETE_WINDOW_EXPIRED');
        $this->getJson('/api/v1/me')->assertJsonPath('data.plan.allowances.family_members.used', 1);
    }

    public function test_permanent_delete_rejects_linked_history_and_archive_preserves_remittance(): void
    {
        $family = $this->postJson('/api/v1/sender-recipients', ['name' => 'Linked Family'])
            ->assertCreated()->json('data');
        $remittance = $this->senderRemittance([
            'recipient_type' => 'family', 'recipient_id' => $family['id'],
        ]);
        $this->deleteJson("/api/v1/sender-recipients/{$family['id']}/delete-new")
            ->assertStatus(409)->assertJsonPath('code', 'PROFILE_HAS_RECORDS');

        $this->postJson("/api/v1/sender-recipients/{$family['id']}/archive")->assertOk();
        $this->getJson("/api/v1/sender-remittances/{$remittance['id']}")->assertOk()
            ->assertJsonPath('data.recipient.id', $family['id']);

        $this->postJson('/api/v1/sender-remittances', $this->senderPayload([
            'recipient_type' => 'family', 'recipient_id' => $family['id'],
        ]))->assertStatus(422)->assertJsonPath('error_codes.recipient_id.0', 'recipient_required');
    }

    public function test_archived_customer_is_not_selectable_for_new_manager_transaction(): void
    {
        $customer = $this->customer();
        $transaction = $this->transaction($customer['id']);
        $this->deleteJson("/api/v1/customers/{$customer['id']}/delete-new")
            ->assertStatus(409)->assertJsonPath('code', 'PROFILE_HAS_RECORDS');

        $this->postJson("/api/v1/customers/{$customer['id']}/archive")->assertOk();
        $this->getJson("/api/v1/manager-transactions/{$transaction['id']}")->assertOk();
        $this->postJson('/api/v1/manager-transactions', $this->txPayload($customer['id']))
            ->assertStatus(422)->assertJsonPath('error_codes.customer_id.0', 'customer_required');
    }

    public function test_another_business_cannot_archive_or_permanently_delete_profiles(): void
    {
        $family = $this->postJson('/api/v1/sender-recipients', ['name' => 'Private Family'])
            ->assertCreated()->json('data');
        $customer = $this->postJson('/api/v1/customers', ['name' => 'Private Customer'])
            ->assertCreated()->json('data');

        $this->actAs($this->makeBusiness());
        $this->postJson("/api/v1/sender-recipients/{$family['id']}/archive")->assertNotFound();
        $this->deleteJson("/api/v1/sender-recipients/{$family['id']}/delete-new")->assertNotFound();
        $this->postJson("/api/v1/customers/{$customer['id']}/archive")->assertNotFound();
        $this->deleteJson("/api/v1/customers/{$customer['id']}/delete-new")->assertNotFound();
    }
}
