<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Manager customer transactions (Money Rules 3.1). paid_bdt is the sum of bdt_payments, kept in step by the server. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('manager_transactions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->string('customer_name', 60);                     // snapshot

            $table->string('currency', 3);
            $table->decimal('amount', 18, 2);
            $table->decimal('rate', 10, 2);
            $table->decimal('commission', 18, 2)->default(0);
            $table->string('receive_method', 24);
            $table->string('pay_to', 200)->nullable();
            $table->json('bank')->nullable();
            $table->decimal('withdrawal_charge', 18, 2)->default(0);
            $table->string('sending_number', 20)->nullable();

            $table->string('status', 20);                            // currency_pending | currency_received | bdt_sent
            $table->decimal('base_bdt', 18, 2);
            $table->decimal('obligation_bdt', 18, 2);
            $table->decimal('paid_bdt', 18, 2)->default(0);

            $table->timestamp('occurred_at');                        // UTC
            $table->string('timezone', 64);                          // the timezone it was entered in
            $table->timestamps();
            $table->softDeletes();

            $table->index(['business_id', 'occurred_at']);
            $table->index(['business_id', 'customer_id']);
            $table->index(['business_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('manager_transactions');
    }
};
