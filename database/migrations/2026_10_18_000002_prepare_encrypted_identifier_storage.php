
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Convert JSON-constrained columns to ordinary LONGTEXT.
        // Verified on MariaDB 10.4.32 using a disposable table.
        DB::statement(
            "ALTER TABLE `customers`
             MODIFY COLUMN `bkash_numbers` LONGTEXT NULL,
             MODIFY COLUMN `nagad_numbers` LONGTEXT NULL,
             MODIFY COLUMN `rocket_numbers` LONGTEXT NULL,
             MODIFY COLUMN `banks` LONGTEXT NULL"
        );

        DB::statement(
            "ALTER TABLE `manager_transactions`
             MODIFY COLUMN `bank` LONGTEXT NULL"
        );

        // Expand identifier fields to accommodate ciphertext.
        DB::statement(
            "ALTER TABLE `customers`
             MODIFY COLUMN `phone` LONGTEXT NULL"
        );

        DB::statement(
            "ALTER TABLE `manager_transactions`
             MODIFY COLUMN `pay_to` LONGTEXT NULL,
             MODIFY COLUMN `sending_number` LONGTEXT NULL"
        );
    }

    public function down(): void
    {
        // Restoring JSON constraints or VARCHAR lengths after
        // encryption could reject ciphertext or lose data.
        throw new RuntimeException(
            'Manual, data-aware rollback required for encryption storage migration.'
        );
    }
};
