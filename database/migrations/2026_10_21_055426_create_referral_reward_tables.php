<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * One server-generated invite code per business/account.
         *
         * Invite codes are shareable identifiers, not secrets.
         * The server owns generation and uniqueness.
         */
        Schema::create('business_referral_profiles', function (Blueprint $table) {
            $table->id();

            $table->foreignId('business_id')
                ->constrained('businesses')
                ->cascadeOnDelete();

            $table->string('invite_code', 32);

            $table->timestamps();

            $table->unique('business_id');
            $table->unique('invite_code');
        });

        /*
         * One referral relationship per referred business.
         *
         * A business may invite several other businesses, but a referred
         * business can only belong to one inviter.
         *
         * The referral does NOT qualify merely because the code was used.
         * Qualification happens only after an approved paid Unlimited
         * payment for the referred business.
         */
        Schema::create('business_referrals', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('inviter_business_id')
                ->constrained('businesses')
                ->cascadeOnDelete();

            $table->foreignId('referred_business_id')
                ->constrained('businesses')
                ->cascadeOnDelete();

            /*
             * claimed | qualified
             */
            $table->string('status', 20)->default('claimed');

            /*
             * Server timestamp for when the invite was accepted.
             */
            $table->timestamp('claimed_at');

            /*
             * The referred friend receives the referral 7-day trial.
             *
             * This timestamp provides referral-specific idempotency/audit
             * evidence in addition to business_subscriptions.trial_used_at.
             */
            $table->timestamp('referred_trial_granted_at')->nullable();

            /*
             * Only an approved paid Unlimited request may qualify
             * the referral.
             *
             * Global uniqueness prevents one approved payment from
             * qualifying multiple referral relationships.
             */
            $table->foreignId('qualifying_payment_request_id')
                ->nullable()
                ->constrained('plan_payment_requests')
                ->restrictOnDelete();

            $table->timestamp('qualified_at')->nullable();

            /*
             * 1..5 for qualified referrals.
             *
             * The service will assign this while holding the inviter
             * business lock. The composite unique key is an additional
             * concurrency guard.
             */
            $table->unsignedTinyInteger('qualification_sequence')->nullable();

            $table->timestamps();

            /*
             * A business can only have one inviter.
             */
            $table->unique('referred_business_id');

            /*
             * A paid request can qualify at most one referral.
             */
            $table->unique(
                'qualifying_payment_request_id',
                'business_referrals_payment_unique'
            );

            /*
             * Prevent two concurrent qualifications from receiving
             * the same slot for the same inviter.
             */
            $table->unique(
                ['inviter_business_id', 'qualification_sequence'],
                'business_referrals_qualification_slot_unique'
            );

            $table->index(
                ['inviter_business_id', 'status', 'created_at'],
                'business_referrals_inviter_status_index'
            );
        });

        /*
         * Exactly one inviter reward per qualifying referral.
         *
         * Reward types:
         *
         * unlimited_days
         *     Free underlying plan -> +7 days Unlimited.
         *     Applied immediately after qualification.
         *
         * renewal_discount_bdt
         *     Active paid-Unlimited underlying plan -> 150 BDT off
         *     one future renewal.
         *
         * Discount lifecycle:
         * available -> reserved -> applied
         *
         * A rejected renewal releases the reservation back to available.
         */
        Schema::create('referral_rewards', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('referral_id')
                ->constrained('business_referrals')
                ->restrictOnDelete();

            /*
             * The inviter business receiving the reward.
             *
             * Stored directly for efficient server-side reward lookup.
             */
            $table->foreignId('beneficiary_business_id')
                ->constrained('businesses')
                ->restrictOnDelete();

            /*
             * unlimited_days | renewal_discount_bdt
             */
            $table->string('reward_type', 32);

            /*
             * Only one value is populated according to reward_type.
             */
            $table->unsignedTinyInteger('unlimited_days')->nullable();
            $table->unsignedSmallInteger('discount_bdt')->nullable();

            /*
             * available | reserved | applied
             *
             * +7-day rewards are normally created directly as applied
             * because the entitlement is granted during qualification.
             *
             * 150 BDT rewards begin as available.
             */
            $table->string('status', 20)->default('available');

            /*
             * For a 150 BDT reward:
             *
             * Set when the reward is attached to a pending renewal.
             * Only one reward may be reserved for a payment request.
             */
            $table->foreignId('reserved_for_payment_request_id')
                ->nullable()
                ->constrained('plan_payment_requests')
                ->restrictOnDelete();

            $table->timestamp('reserved_at')->nullable();

            /*
             * Set only when the corresponding renewal is approved.
             *
             * For an immediately applied +7-day reward this remains NULL,
             * while applied_at records when the entitlement was granted.
             */
            $table->foreignId('applied_to_payment_request_id')
                ->nullable()
                ->constrained('plan_payment_requests')
                ->restrictOnDelete();

            $table->timestamp('applied_at')->nullable();

            $table->timestamps();

            /*
             * One qualifying referral can create exactly one reward.
             */
            $table->unique('referral_id');

            /*
             * Enforces no reward stacking on a single renewal request.
             */
            $table->unique(
                'reserved_for_payment_request_id',
                'referral_rewards_reserved_payment_unique'
            );

            $table->unique(
                'applied_to_payment_request_id',
                'referral_rewards_applied_payment_unique'
            );

            $table->index(
                ['beneficiary_business_id', 'status', 'created_at'],
                'referral_rewards_business_status_index'
            );

            $table->index(
                ['beneficiary_business_id', 'reward_type', 'status'],
                'referral_rewards_available_index'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referral_rewards');
        Schema::dropIfExists('business_referrals');
        Schema::dropIfExists('business_referral_profiles');
    }
};