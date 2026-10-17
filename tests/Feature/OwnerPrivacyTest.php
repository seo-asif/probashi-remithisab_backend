<?php

namespace Tests\Feature;

use App\Enums\BusinessRole;
use App\Models\Business;
use App\Models\User;
use App\Support\Tenancy\CurrentBusiness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\MakesTenants;
use Tests\Support\OwnerProbe;
use Tests\Support\ProbeRoutes;
use Tests\TestCase;

/** Sender Mode privacy (spec 22.5 and 22.7): private to the creating user, even inside one business. */
class OwnerPrivacyTest extends TestCase
{
    use MakesTenants, RefreshDatabase;

    private Business $biz;
    private User $owner;
    private User $admin;
    private User $alice;
    private User $bob;

    protected function setUp(): void
    {
        parent::setUp();
        ProbeRoutes::register();

        $this->biz = $this->makeBusiness(['name' => 'Shared']);
        $this->owner = $this->makeMember($this->biz, BusinessRole::Owner);
        $this->admin = $this->makeMember($this->biz, BusinessRole::Administrator);
        $this->alice = $this->makeMember($this->biz, BusinessRole::Operator);
        $this->bob = $this->makeMember($this->biz, BusinessRole::Operator);
    }

    private function createProbe(User $user, string $label, ?Business $business = null): OwnerProbe
    {
        return app(CurrentBusiness::class)->runAsUser($business ?? $this->biz, $user->id, fn() => OwnerProbe::create(['label' => $label]));
    }

    public function test_each_user_lists_only_their_own_private_records(): void
    {
        $this->createProbe($this->alice, 'alice-1');
        $this->createProbe($this->bob, 'bob-1');

        Sanctum::actingAs($this->alice);
        $this->getJson('/api/v1/_owner_probe')->assertOk()->assertJsonPath('data', ['alice-1']);

        Sanctum::actingAs($this->bob);
        $this->getJson('/api/v1/_owner_probe')->assertOk()->assertJsonPath('data', ['bob-1']);
    }

    public function test_the_business_owner_and_administrator_have_no_override(): void
    {
        $record = $this->createProbe($this->alice, 'private');

        foreach ([$this->owner, $this->admin] as $privileged) {
            Sanctum::actingAs($privileged);

            $this->getJson('/api/v1/_owner_probe')->assertOk()->assertJsonPath('data', []);

            $this->getJson('/api/v1/_owner_probe/' . $record->id)->assertStatus(404)->assertJsonPath('code', 'NOT_FOUND');
        }
    }

    public function test_another_users_record_looks_exactly_like_a_missing_one(): void
    {
        $record = $this->createProbe($this->alice, 'private');
        Sanctum::actingAs($this->bob);

        $foreign = $this->getJson('/api/v1/_owner_probe/' . $record->id);
        $missing = $this->getJson('/api/v1/_owner_probe/999999');

        $this->assertSame($missing->json(), $foreign->json());
    }

    public function test_another_user_cannot_update_or_delete(): void
    {
        $record = $this->createProbe($this->alice, 'keep');
        Sanctum::actingAs($this->admin);

        $this->patchJson('/api/v1/_owner_probe/' . $record->id, ['label' => 'hacked'])->assertStatus(404);
        $this->deleteJson('/api/v1/_owner_probe/' . $record->id)->assertStatus(404);

        $this->assertSame('keep', OwnerProbe::withoutGlobalScopes()->find($record->id)->label);
    }

    public function test_created_records_belong_to_the_current_user_even_if_the_body_says_otherwise(): void
    {
        Sanctum::actingAs($this->alice);

        $id = $this->postJson('/api/v1/_owner_probe', ['label' => 'x', 'owner_user_id' => $this->bob->id, 'business_id' => 999])
            ->assertStatus(201)->json('data.id');

        $row = OwnerProbe::withoutGlobalScopes()->find($id);
        $this->assertSame($this->alice->id, $row->owner_user_id);
        $this->assertSame($this->biz->id, $row->business_id);
    }

    public function test_a_user_in_another_business_sees_nothing(): void
    {
        $this->createProbe($this->alice, 'private');
        $otherBusiness = $this->makeBusiness();

        Sanctum::actingAs($this->makeMember($otherBusiness));

        $this->getJson('/api/v1/_owner_probe')->assertOk()->assertJsonPath('data', []);
    }

    public function test_business_wide_jobs_cannot_see_private_records(): void
    {
        $this->createProbe($this->alice, 'private');
        $current = app(CurrentBusiness::class);

        $this->assertSame(0, $current->runAs($this->biz, fn() => OwnerProbe::count()));
        $this->assertSame(1, $current->runAsUser($this->biz, $this->alice->id, fn() => OwnerProbe::count()));
        $this->assertSame(0, $current->runAsUser($this->biz, $this->bob->id, fn() => OwnerProbe::count()));
    }

    public function test_queries_fail_closed_with_no_user(): void
    {
        $this->createProbe($this->alice, 'private');

        $this->assertSame(0, OwnerProbe::count());
    }

    public function test_creating_for_another_user_is_refused(): void
    {
        $this->expectException(\LogicException::class);

        app(CurrentBusiness::class)->runAsUser($this->biz, $this->alice->id, fn() => OwnerProbe::create(['label' => 'x', 'owner_user_id' => $this->bob->id]));
    }

    public function test_creating_without_a_user_is_refused(): void
    {
        $this->expectException(\LogicException::class);

        app(CurrentBusiness::class)->runAs($this->biz, fn() => OwnerProbe::create(['label' => 'x']));
    }

    public function test_the_owner_cannot_be_changed_later(): void
    {
        $record = $this->createProbe($this->alice, 'mine');

        $this->expectException(\LogicException::class);
        app(CurrentBusiness::class)->runAsUser($this->biz, $this->alice->id, function () use ($record) {
            $record->owner_user_id = $this->bob->id;
            $record->save();
        });
    }
}
