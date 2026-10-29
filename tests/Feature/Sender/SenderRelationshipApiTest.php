<?php

namespace Tests\Feature\Sender;

use App\Models\SenderRecipient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Phase3Fixtures;
use Tests\TestCase;

class SenderRelationshipApiTest extends TestCase
{
    use Phase3Fixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actAs($this->makeBusiness());
    }

    public function test_individual_relationships_can_be_created(): void
    {
        foreach (['Wife', 'Husband', 'Son', 'Daughter'] as $relationship) {
            $response = $this->postJson('/api/v1/sender-recipients', [
                'name' => 'Family ' . $relationship,
                'relationship' => $relationship,
            ])->assertCreated();

            $response->assertJsonPath('data.relationship', $relationship);
        }
    }

    public function test_legacy_relationships_cannot_be_selected_for_new_recipients(): void
    {
        foreach (['Wife / Husband', 'Son / Daughter'] as $relationship) {
            $this->postJson('/api/v1/sender-recipients', [
                'name' => 'Legacy Family',
                'relationship' => $relationship,
            ])->assertStatus(422);
        }
    }

    public function test_unchanged_legacy_relationship_can_be_preserved_during_edit(): void
    {
        $family = $this->postJson('/api/v1/sender-recipients', [
            'name' => 'Existing Family',
            'relationship' => 'Wife',
        ])->assertCreated()->json('data');

        SenderRecipient::withoutGlobalScopes()
            ->where('uuid', $family['id'])
            ->update(['relationship' => 'Wife / Husband']);

        $this->patchJson("/api/v1/sender-recipients/{$family['id']}", [
            'name' => 'Updated Family',
        ])->assertOk()
          ->assertJsonPath('data.relationship', 'Wife / Husband');

        $this->patchJson("/api/v1/sender-recipients/{$family['id']}", [
            'relationship' => 'Wife / Husband',
        ])->assertOk()
          ->assertJsonPath('data.relationship', 'Wife / Husband');
    }

    public function test_legacy_relationship_cannot_be_assigned_to_another_recipient(): void
    {
        $family = $this->postJson('/api/v1/sender-recipients', [
            'name' => 'New Family',
            'relationship' => 'Wife',
        ])->assertCreated()->json('data');

        $this->patchJson("/api/v1/sender-recipients/{$family['id']}", [
            'relationship' => 'Wife / Husband',
        ])->assertStatus(422);
    }

    public function test_other_business_cannot_use_legacy_edit_exception(): void
    {
        $family = $this->postJson('/api/v1/sender-recipients', [
            'name' => 'Private Family',
            'relationship' => 'Wife',
        ])->assertCreated()->json('data');

        SenderRecipient::withoutGlobalScopes()
            ->where('uuid', $family['id'])
            ->update(['relationship' => 'Wife / Husband']);

        $this->actAs($this->makeBusiness());

        $this->patchJson("/api/v1/sender-recipients/{$family['id']}", [
            'relationship' => 'Wife / Husband',
        ])->assertNotFound();
    }
}
