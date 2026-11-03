<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        // Base TestCase calls this before RefreshDatabase initializes.
        $app = parent::createApplication();
        $this->assertSafeTestingDatabase();

        return $app;
    }

    private function assertSafeTestingDatabase(): void
    {
        if (! app()->environment('testing')) {
            throw new RuntimeException('Refusing tests: APP_ENV must be testing.');
        }

        $connection = config('database.default');
        $database = config("database.connections.{$connection}.database");

        if ($connection !== 'mysql' || $database !== 'probashi_remithisab_test') {
            throw new RuntimeException('Refusing tests: database must be MySQL probashi_remithisab_test.');
        }

        // Resolve the selected live PDO connection rather than trusting env alone.
        $actual = \Illuminate\Support\Facades\DB::connection($connection)
            ->selectOne('SELECT DATABASE() AS selected_db');

        if (($actual->selected_db ?? null) !== 'probashi_remithisab_test') {
            throw new RuntimeException('Refusing tests: actual connected database is not the test database.');
        }
    }
}