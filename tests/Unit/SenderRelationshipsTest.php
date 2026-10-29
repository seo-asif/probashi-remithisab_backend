<?php

namespace Tests\Unit;

use App\Support\Sender\SenderOptions;
use PHPUnit\Framework\TestCase;

final class SenderRelationshipsTest extends TestCase
{
    public function test_only_individual_relationships_are_selectable(): void
    {
        $expected = [
            'Mother',
            'Father',
            'Wife',
            'Husband',
            'Son',
            'Daughter',
            'Brother',
            'Sister',
            'Other relative',
            'Friend',
            'Other',
        ];

        $this->assertSame($expected, SenderOptions::RELATIONSHIPS);
    }

    public function test_legacy_relationships_are_kept_separately(): void
    {
        $this->assertSame(
            ['Wife / Husband', 'Son / Daughter'],
            SenderOptions::LEGACY_RELATIONSHIPS
        );

        foreach (SenderOptions::LEGACY_RELATIONSHIPS as $relationship) {
            $this->assertNotContains($relationship, SenderOptions::RELATIONSHIPS);
        }
    }
}
