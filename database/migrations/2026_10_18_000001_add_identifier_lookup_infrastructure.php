
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Customer phone: deterministic HMAC lookup.
        Schema::table('customers', function (Blueprint $table) {
            $table->char('phone_hash', 64)->nullable();

            $table->index(
                ['business_id', 'phone_hash'],
                'customers_business_phone_hash_idx'
            );
        });

        // Operator's own sending numbers.
        Schema::table('sending_numbers', function (Blueprint $table) {
            $table->char('number_hash', 64)->nullable();

            $table->index(
                ['business_id', 'number_hash'],
                'sending_numbers_business_hash_idx'
            );
        });

        // Historical transaction destination lookup.
        Schema::table('manager_transactions', function (Blueprint $table) {
            $table->char('destination_hash', 64)->nullable();

            $table->index(
                ['business_id', 'destination_hash'],
                'manager_transactions_dest_hash_idx'
            );
        });

        // Derived customer wallet and bank-account lookup records.
        Schema::create('customer_identifier_lookups', function (Blueprint $table) {
            $table->id();

            $table->foreignId('business_id')
                ->constrained('businesses')
                ->cascadeOnDelete();

            $table->foreignId('customer_id')
                ->constrained('customers')
                ->cascadeOnDelete();

            $table->string('type', 24);
            $table->char('identifier_hash', 64);
            $table->timestamp('created_at')->nullable();

            $table->index(
                ['business_id', 'type', 'identifier_hash'],
                'customer_identifiers_search_idx'
            );

            $table->unique(
                ['customer_id', 'type', 'identifier_hash'],
                'customer_identifiers_customer_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_identifier_lookups');

        Schema::table('manager_transactions', function (Blueprint $table) {
            $table->dropIndex('manager_transactions_dest_hash_idx');
            $table->dropColumn('destination_hash');
        });

        Schema::table('sending_numbers', function (Blueprint $table) {
            $table->dropIndex('sending_numbers_business_hash_idx');
            $table->dropColumn('number_hash');
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->dropIndex('customers_business_phone_hash_idx');
            $table->dropColumn('phone_hash');
        });
    }
};
