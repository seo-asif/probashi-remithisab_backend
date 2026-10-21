<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * Expiry timestamps are server-controlled immutable values.
         *
         * On this MariaDB/MySQL configuration, these TIMESTAMP columns
         * received implicit DEFAULT CURRENT_TIMESTAMP / ON UPDATE
         * CURRENT_TIMESTAMP behavior.
         *
         * DATETIME avoids that database-side mutation.
         */

        Schema::table('otp_codes', function (Blueprint $table) {
            $table->dateTime('expires_at', 6)->change();
        });

        Schema::table('password_reset_tickets', function (Blueprint $table) {
            $table->dateTime('expires_at')->change();
        });
    }

    public function down(): void
    {
        /*
         * Intentionally do not restore the unsafe implicit TIMESTAMP
         * behavior. Converting these columns back on this database
         * configuration could reintroduce automatic expiry mutation.
         */
    }
};