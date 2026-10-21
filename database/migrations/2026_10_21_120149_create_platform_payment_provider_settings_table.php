<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'platform_payment_provider_settings',
            function (Blueprint $table) {
                $table->id();

                /*
                 * Provider identifier.
                 *
                 * Current:
                 * - manual_bkash
                 *
                 * Future providers such as UddoktaPay can be added
                 * without changing the subscription/payment-request
                 * architecture.
                 */
                $table->string('provider', 32)->unique();

                $table->boolean('is_enabled')->default(false);

                /*
                 * Public destination shown to the customer.
                 *
                 * For manual_bkash this is the receiving bKash number.
                 * This column must never contain PINs, API secrets,
                 * tokens or gateway credentials.
                 */
                $table->string(
                    'public_account_identifier',
                    64,
                )->nullable();

                /*
                 * Audit which platform administrator last changed
                 * the provider configuration.
                 */
                $table->foreignId(
                    'updated_by_platform_admin_id',
                )->nullable();

                $table->foreign(
                    'updated_by_platform_admin_id',
                    'ppps_updated_by_admin_fk',
                )
                    ->references('id')
                    ->on('platform_admins')
                    ->restrictOnDelete();
                $table->timestamps();
            },
        );

        /*
         * Keep a durable singleton row for the current provider.
         *
         * It starts disabled. A platform administrator must configure
         * the real receiving number before customers can use it.
         */
        DB::table('platform_payment_provider_settings')->insert([
            'provider'                     => 'manual_bkash',
            'is_enabled'                   => false,
            'public_account_identifier'    => null,
            'updated_by_platform_admin_id' => null,
            'created_at'                   => now(),
            'updated_at'                   => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'platform_payment_provider_settings',
        );
    }
};
