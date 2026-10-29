<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['sender_remittances', 'manager_transactions'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                // Null means no refund has ever been granted for this record.
                $table->dateTime('quota_refunded_at', 6)->nullable();
                // A restored record reconsumes the original slot only once.
                $table->dateTime('quota_refund_reapplied_at', 6)->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['sender_remittances', 'manager_transactions'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->dropColumn(['quota_refunded_at', 'quota_refund_reapplied_at']);
            });
        }
    }
};
