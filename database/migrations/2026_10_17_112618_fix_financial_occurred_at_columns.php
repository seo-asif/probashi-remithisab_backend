<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            'ALTER TABLE manager_transactions
             MODIFY occurred_at DATETIME NOT NULL'
        );

        DB::statement(
            'ALTER TABLE manager_remittances
             MODIFY occurred_at DATETIME NOT NULL'
        );
    }

    public function down(): void
    {
        // Intentionally left empty to avoid reintroducing
        // automatic timestamp updates or changing financial dates.
    }
};
