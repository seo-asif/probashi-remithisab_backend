<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * Use DATETIME deliberately.
         *
         * On this MySQL/MariaDB configuration, the original first
         * non-null TIMESTAMP column received implicit:
         *
         * DEFAULT CURRENT_TIMESTAMP
         * ON UPDATE CURRENT_TIMESTAMP
         *
         * claimed_at is immutable audit/security evidence and must
         * never be changed automatically when the referral row is
         * updated.
         */
        Schema::table('business_referrals', function (Blueprint $table) {
            $table->dateTime('claimed_at')->change();
        });
    }

    public function down(): void
    {
        /*
         * Keep rollback safe from the same implicit TIMESTAMP behavior.
         *
         * There is intentionally no conversion back to TIMESTAMP here,
         * because doing so could restore the database-level
         * ON UPDATE CURRENT_TIMESTAMP bug.
         */
    }
};