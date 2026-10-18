<?php

namespace Tests\Unit\Security;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TestDatabaseIsolationTest extends TestCase
{
    public function test_phpunit_uses_the_configured_test_database(): void
    {
        $actual = DB::connection()->getDatabaseName();
        $expected = env('DB_DATABASE');

        $this->assertNotEmpty($actual);
        $this->assertSame($expected, $actual);

        $this->assertNotSame(
            'probashi_remithisab',
            $actual,
            'Tests must not connect to the development database.'
        );
    }
}
