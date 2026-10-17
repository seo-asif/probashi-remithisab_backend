<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Money Rules 3.2: every payment of taka to a customer's family is one entry. Entries are never edited:
 * a wrong one is corrected by a reversal entry (negative amount). Entries removed by a status change are
 * soft-deleted and logged, never erased.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bdt_payments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('transaction_id')->constrained('manager_transactions')->cascadeOnDelete();

            $table->decimal('amount_bdt', 18, 2);                    // negative for a reversal
            $table->date('paid_on');
            $table->boolean('auto')->default(false);                 // added by "mark as sent"
            $table->foreignId('reverses_payment_id')->nullable()->constrained('bdt_payments')->nullOnDelete();
            $table->string('note', 100)->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['business_id', 'transaction_id']);
            $table->index(['business_id', 'paid_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bdt_payments');
    }
};
