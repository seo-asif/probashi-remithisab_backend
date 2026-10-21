<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * PLATFORM ADMINS
         *
         * This is deliberately separate from business_memberships.
         *
         * A BusinessRole::Owner or BusinessRole::Administrator is a
         * tenant role and must never gain payment-approval authority
         * from that role.
         */
        Schema::create('platform_admins', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained('users')
                ->restrictOnDelete();

            $table->string('status', 20)->default('active');

            $table->timestamps();

            $table->unique('user_id');
            $table->index('status');
        });

        /*
         * PLAN PAYMENT REQUESTS
         *
         * Sensitive values:
         * - trx_id will be encrypted by the Eloquent model.
         * - payer_number will be encrypted by the Eloquent model.
         *
         * trx_id_hash is a deterministic HMAC lookup value generated
         * with IdentifierHasher. It is safe to index and allows the
         * server to enforce one use of a bKash TrxID without storing
         * the plaintext TrxID in an indexed column.
         *
         * Product types planned by the entitlement layer:
         * - unlimited
         * - sender_topup
         * - manager_topup
         *
         * Paid top-up request creation remains disabled until its
         * server-authoritative BDT price is defined.
         */
        Schema::create('plan_payment_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('business_id')
                ->constrained('businesses')
                ->restrictOnDelete();

            $table->foreignId('submitted_by_user_id')
                ->constrained('users')
                ->restrictOnDelete();

            /*
             * Required only for a Sender top-up.
             *
             * This makes the recipient of the +20 durable and
             * unambiguous. Unlimited and Manager top-up requests
             * leave this NULL.
             */
            $table->foreignId('beneficiary_user_id')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();

            $table->string('product_type', 32);

            /*
             * Used only by Unlimited:
             * 1, 3, 6 or 12 months.
             *
             * Top-up requests leave this NULL.
             */
            $table->unsignedTinyInteger('package_months')->nullable();

            /*
             * Server-authoritative amount.
             *
             * Flutter never decides this value.
             */
            $table->unsignedInteger('expected_amount_bdt');

            /*
             * Laravel encrypted ciphertext.
             *
             * Do not index ciphertext because encryption is
             * intentionally non-deterministic.
             */
            $table->longText('trx_id');

            /*
             * HMAC-SHA-256 of the normalized TrxID.
             *
             * Global uniqueness prevents one bKash transaction from
             * being used to activate multiple businesses/plans.
             */
            $table->char('trx_id_hash', 64)->unique();

            /*
             * The 11-digit bKash number the customer says the
             * payment was sent from.
             *
             * Stored encrypted at rest.
             */
            $table->longText('payer_number');

            /*
             * pending | approved | rejected
             */
            $table->string('status', 20)->default('pending');

            /*
             * Platform administrator who made the decision.
             *
             * This references platform_admins, NOT a business role.
             */
            $table->foreignId('decided_by_platform_admin_id')
                ->nullable()
                ->constrained('platform_admins')
                ->restrictOnDelete();

            /*
             * Human-readable rejection/decision reason.
             *
             * The prototype displays a rejection reason to the user.
             */
            $table->string('decision_reason', 255)->nullable();

            $table->timestamp('decided_at')->nullable();

            /*
             * Idempotency/audit marker.
             *
             * Approval code must never apply PaidAccessService or
             * PlanTopUpService again after this has been set.
             */
            $table->timestamp('applied_at')->nullable();

            $table->timestamps();

            $table->index([
                'business_id',
                'status',
                'created_at',
            ]);

            $table->index([
                'submitted_by_user_id',
                'status',
            ]);

            $table->index([
                'status',
                'created_at',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_payment_requests');
        Schema::dropIfExists('platform_admins');
    }
};
