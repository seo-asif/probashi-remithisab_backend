<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            'ALTER TABLE otp_codes
             MODIFY expires_at DATETIME(6) NOT NULL,
             MODIFY consumed_at TIMESTAMP(6) NULL,
             MODIFY invalidated_at TIMESTAMP(6) NULL'
        );
    }

    public function down(): void
    {
        DB::statement(
            'ALTER TABLE otp_codes
             MODIFY expires_at TIMESTAMP NOT NULL,
             MODIFY consumed_at TIMESTAMP NULL,
             MODIFY invalidated_at TIMESTAMP NULL'
        );
    }
};
