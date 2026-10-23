<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Widen rate precision without changing existing financial values. */
return new class extends Migration
{
    private const TABLES = ['sender_remittances', 'manager_transactions', 'manager_remittances'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $schema) use ($table) {
                $column = $schema->decimal('rate', 10, 4);
                if ($table === 'manager_remittances') {
                    $column->default(0);
                }
                $column->change();
            });
        }
    }

    public function down(): void
    {
        // Refuse to silently destroy four-decimal historical rates.
        foreach (self::TABLES as $table) {
            if (DB::table($table)->whereRaw('rate <> ROUND(rate, 2)')->exists()) {
                throw new RuntimeException("Cannot downgrade {$table}.rate while four-decimal rates exist.");
            }
        }
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $schema) use ($table) {
                $column = $schema->decimal('rate', 10, 2);
                if ($table === 'manager_remittances') {
                    $column->default(0);
                }
                $column->change();
            });
        }
    }
};
