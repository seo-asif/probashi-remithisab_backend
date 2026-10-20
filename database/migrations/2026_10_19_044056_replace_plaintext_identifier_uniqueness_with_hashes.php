<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * CUSTOMERS
         *
         * Replace:
         *   UNIQUE (business_id, active_phone_key)
         *
         * With:
         *   UNIQUE (business_id, active_phone_hash)
         *
         * NULL remains intentionally reusable for:
         * - soft-deleted customers
         * - customers without a phone
         */

        DB::statement("
            ALTER TABLE customers
            ADD COLUMN active_phone_hash CHAR(64)
            GENERATED ALWAYS AS (
                IF(deleted_at IS NULL, phone_hash, NULL)
            ) STORED
        ");

        DB::statement("
            ALTER TABLE customers
            ADD UNIQUE INDEX customers_business_active_phone_hash_unique
            (business_id, active_phone_hash)
        ");

        DB::statement("
            ALTER TABLE customers
            DROP INDEX customers_business_id_active_phone_key_unique
        ");

        DB::statement("
            ALTER TABLE customers
            DROP COLUMN active_phone_key
        ");

        /*
         * SENDING NUMBERS
         *
         * number will eventually contain Laravel encrypted ciphertext,
         * therefore VARCHAR(20) is no longer suitable.
         */

        DB::statement("
            ALTER TABLE sending_numbers
            MODIFY COLUMN number LONGTEXT NOT NULL
        ");

        DB::statement("
            ALTER TABLE sending_numbers
            ADD COLUMN active_number_hash CHAR(64)
            GENERATED ALWAYS AS (
                IF(deleted_at IS NULL, number_hash, NULL)
            ) STORED
        ");

        DB::statement("
            ALTER TABLE sending_numbers
            ADD UNIQUE INDEX sending_numbers_business_active_number_hash_unique
            (business_id, active_number_hash)
        ");

        DB::statement("
            ALTER TABLE sending_numbers
            DROP INDEX sending_numbers_business_id_active_number_unique
        ");

        DB::statement("
            ALTER TABLE sending_numbers
            DROP COLUMN active_number
        ");
    }

    public function down(): void
    {
        /*
         * SENDING NUMBERS
         *
         * This rollback is safe only while number still contains plaintext
         * values short enough for VARCHAR(20). Once application-level
         * encryption is enabled and encrypted ciphertext has been stored,
         * do not casually roll this migration back.
         */

        DB::statement("
            ALTER TABLE sending_numbers
            DROP INDEX sending_numbers_business_active_number_hash_unique
        ");

        DB::statement("
            ALTER TABLE sending_numbers
            DROP COLUMN active_number_hash
        ");

        DB::statement("
            ALTER TABLE sending_numbers
            MODIFY COLUMN number VARCHAR(20) NOT NULL
        ");

        DB::statement("
            ALTER TABLE sending_numbers
            ADD COLUMN active_number VARCHAR(20)
            GENERATED ALWAYS AS (
                IF(deleted_at IS NULL, number, NULL)
            ) STORED
        ");

        DB::statement("
            ALTER TABLE sending_numbers
            ADD UNIQUE INDEX sending_numbers_business_id_active_number_unique
            (business_id, active_number)
        ");

        /*
         * CUSTOMERS
         */

        DB::statement("
            ALTER TABLE customers
            DROP INDEX customers_business_active_phone_hash_unique
        ");

        DB::statement("
            ALTER TABLE customers
            DROP COLUMN active_phone_hash
        ");

        DB::statement("
            ALTER TABLE customers
            ADD COLUMN active_phone_key VARCHAR(24)
            GENERATED ALWAYS AS (
                IF(deleted_at IS NULL, phone_key, NULL)
            ) STORED
        ");

        DB::statement("
            ALTER TABLE customers
            ADD UNIQUE INDEX customers_business_id_active_phone_key_unique
            (business_id, active_phone_key)
        ");
    }
};