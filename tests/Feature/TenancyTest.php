<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Support\Tenancy\CurrentBusiness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\MakesTenants;
use Tests\Support\ProbeRoutes;
use Tests\Support\TenancyProbe;
use Tests\TestCase;

class TenancyTest extends TestCase
{
    use MakesTenants, RefreshDatabase;

    private Business $a;
    private Business $b;

    protected function setUp(): void
    {
        parent::setUp();
        ProbeRoutes::register();

        $this->a = $this->makeBusiness(['name' => 'A']);
        $this->b = $this->makeBusiness(['name' => 'B']);
    }

    private function seedProbe(Business $business, string $label): TenancyProbe
    {
        return app(CurrentBusiness::class)->runAs($business, fn () => TenancyProbe::create(['label' => $label]));
    }

    public function test_a_business_only_lists_its_own_records(): void
    {
        $this->seedProbe($this->a, 'a1');
        $this->seedProbe($this->b, 'b1');

        Sanctum::actingAs($this->makeMember($this->a));

        $this->getJson('/api/v1/_probe')
            ->assertOk()
            ->assertExactJson(['success' => true, 'code' => 'OK', 'message' => 'OK', 'data' => ['a1']]);
    }

    public function test_a_foreign_record_id_looks_exactly_like_a_missing_one(): void
    {
        $foreign = $this->seedProbe($this->b, 'secret');
        Sanctum::actingAs($this->makeMember($this->a));

        $foreignResponse = $this->getJson('/api/v1/_probe/' . $foreign->id);
        $missingResponse = $this->getJson('/api/v1/_probe/999999');

        $foreignResponse->assertStatus(404)->assertJsonPath('code', 'NOT_FOUND');
        $this->assertSame($missingResponse->json(), $foreignResponse->json());
    }

    public function test_a_business_cannot_update_or_delete_a_foreign_record(): void
    {
        $foreign = $this->seedProbe($this->b, 'keep');
        Sanctum::actingAs($this->makeMember($this->a));

        $this->patchJson('/api/v1/_probe/' . $foreign->id, ['label' => 'hacked'])->assertStatus(404);
        $this->deleteJson('/api/v1/_probe/' . $foreign->id)->assertStatus(404);

        $this->assertSame('keep', TenancyProbe::withoutGlobalScopes()->find($foreign->id)->label);
    }

    public function test_created_records_get_the_current_business_even_if_the_body_has_another(): void
    {
        Sanctum::actingAs($this->makeMember($this->a));

        $id = $this->postJson('/api/v1/_probe', ['label' => 'x', 'business_id' => $this->b->id])
            ->assertStatus(201)->json('data.id');

        $this->assertSame($this->a->id, TenancyProbe::withoutGlobalScopes()->find($id)->business_id);
    }

    public function test_queries_fail_closed_when_there_is_no_current_business(): void
    {
        $this->seedProbe($this->a, 'a1');

        $this->assertSame(0, TenancyProbe::count());
    }

    public function test_run_as_scopes_work_and_restores_the_previous_state(): void
    {
        $current = app(CurrentBusiness::class);
        $this->seedProbe($this->a, 'a1');
        $this->seedProbe($this->b, 'b1');

        $current->runAs($this->a, function () use ($current) {
            $this->assertSame(['a1'], TenancyProbe::pluck('label')->all());

            $current->runAs($this->b, fn () => $this->assertSame(['b1'], TenancyProbe::pluck('label')->all()));

            $this->assertSame(['a1'], TenancyProbe::pluck('label')->all());
        });

        $this->assertNull($current->id());
    }

    public function test_writing_to_another_business_is_refused(): void
    {
        $this->expectException(\LogicException::class);

        app(CurrentBusiness::class)->runAs($this->a, fn () => TenancyProbe::create(['label' => 'x', 'business_id' => $this->b->id]));
    }

    public function test_creating_without_any_business_is_refused(): void
    {
        $this->expectException(\LogicException::class);

        TenancyProbe::create(['label' => 'x']);
    }

    public function test_unscoped_work_must_name_the_business_explicitly(): void
    {
        $current = app(CurrentBusiness::class);

        $current->runUnscoped(fn () => TenancyProbe::create(['label' => 'ok', 'business_id' => $this->a->id]));
        $this->assertSame(1, $current->runUnscoped(fn () => TenancyProbe::count()));

        $this->expectException(\LogicException::class);
        $current->runUnscoped(fn () => TenancyProbe::create(['label' => 'no business']));
    }

    public function test_business_id_cannot_be_changed_later(): void
    {
        $probe = $this->seedProbe($this->a, 'a1');

        $this->expectException(\LogicException::class);
        app(CurrentBusiness::class)->runAs($this->a, function () use ($probe) {
            $probe->business_id = $this->b->id;
            $probe->save();
        });
    }
}
