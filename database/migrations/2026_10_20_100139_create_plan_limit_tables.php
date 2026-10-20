<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * One plan/entitlement per business.
         *
         * No row also means Free. This lets existing businesses continue
         * working safely before a subscription row is explicitly created.
         */
        Schema::create('business_subscriptions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('business_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('tier', 20)->default('free');
            $table->string('access_source', 20)->nullable();

            $table->timestamp('access_starts_at')->nullable();
            $table->timestamp('access_ends_at')->nullable();
            $table->timestamp('trial_used_at')->nullable();

            $table->timestamps();

            $table->unique('business_id');
            $table->index(['tier', 'access_ends_at']);
        });

        /*
         * Business-scoped monthly usage.
         *
         * Manager Mode is shared by the business.
         * The edit allowance is also shared across both modes.
         */
        Schema::create('business_monthly_usage', function (Blueprint $table) {
            $table->id();

            $table->foreignId('business_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->date('period_start');

            $table->unsignedInteger('manager_transactions_used')->default(0);
            $table->unsignedInteger('manager_transaction_bonus')->default(0);
            $table->unsignedInteger('edits_used')->default(0);

            $table->timestamps();

            $table->unique(
                ['business_id', 'period_start'],
                'business_monthly_usage_period_unique'
            );
        });

        /*
         * User-scoped monthly Sender usage.
         *
         * Sender records are private to their creator, so Sender transaction
         * usage must also be tracked per user within the business.
         */
        Schema::create('user_monthly_usage', function (Blueprint $table) {
            $table->id();

            $table->foreignId('business_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->date('period_start');

            $table->unsignedInteger('sender_remittances_used')->default(0);
            $table->unsignedInteger('sender_remittance_bonus')->default(0);

            $table->timestamps();

            $table->unique(
                ['business_id', 'user_id', 'period_start'],
                'user_monthly_usage_period_unique'
            );

            $table->index(['user_id', 'period_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_monthly_usage');
        Schema::dropIfExists('business_monthly_usage');
        Schema::dropIfExists('business_subscriptions');
    }
};