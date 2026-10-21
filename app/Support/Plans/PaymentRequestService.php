<?php

namespace App\Support\Plans;

use App\Enums\AccountStatus;
use App\Enums\BusinessRole;
use App\Enums\PlanPaymentProduct;
use App\Enums\PlanPaymentStatus;
use App\Exceptions\ApiException;
use App\Models\Business;
use App\Models\BusinessMembership;
use App\Models\PlanPaymentRequest;
use App\Models\ReferralReward;
use App\Models\User;
use App\Services\Auth\AuditLogger;
use App\Support\Security\IdentifierHasher;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final class PaymentRequestService
{
    public const MAX_PENDING = 2;

    private const TRX_HASH_DOMAIN = 'plan_payment_trx';

    public function __construct(
        private IdentifierHasher $hasher,
        private ReferralRenewalDiscountService $renewalDiscounts,
        private AuditLogger $auditLogger,
    ) {
    }

    public function createUnlimited(
        Business $business,
        User $submittedBy,
        int $months,
        string $trxId,
        string $payerNumber,
    ): PlanPaymentRequest {
        if (!PlanPackages::isValidMonths($months)) {
            throw new ApiException(
                'PLAN_PACKAGE_INVALID',
                'Choose a valid Unlimited package.',
                422,
            );
        }

        $trxId = $this->normalizeTrxId($trxId);

        if (!preg_match('/\A[A-Z0-9]{10}\z/D', $trxId)) {
            throw new ApiException(
                'PAYMENT_TRX_INVALID',
                'TrxID must contain exactly 10 letters and numbers.',
                422,
            );
        }

        $payerNumber = $this->normalizePayerNumber(
            $payerNumber,
        );

        if (!BangladeshMobileNumber::isValid($payerNumber)) {
            throw new ApiException(
                'PAYMENT_PAYER_NUMBER_INVALID',
                'Enter the 11-digit Bangladesh mobile number used to make the payment.',
                422,
            );
        }

        $trxHash = $this->hasher->hash(
            self::TRX_HASH_DOMAIN,
            $trxId,
        );

        /*
         * Fast failure before taking the business lock.
         *
         * The database UNIQUE constraint remains the final protection
         * against two different businesses submitting the same TrxID
         * concurrently.
         */
        $this->assertTrxUnused($trxHash);

        try {
            return DB::transaction(function () use (
                $business,
                $submittedBy,
                $months,
                $trxId,
                $trxHash,
                $payerNumber,
            ): PlanPaymentRequest {
                /*
                 * Stable per-business mutex.
                 *
                 * This serializes the "maximum two pending requests"
                 * check so two devices cannot both pass it at once.
                 */
                $lockedBusiness = Business::query()
                    ->whereKey($business->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $this->assertCanSubmit(
                    $lockedBusiness,
                    $submittedBy,
                );

                $this->assertPendingSlotAvailable(
                    $lockedBusiness,
                );

                /*
                 * Re-check after taking the lock.
                 *
                 * Global database uniqueness still protects races
                 * involving different businesses.
                 */
                $this->assertTrxUnused($trxHash);

                $payment = PlanPaymentRequest::create([
                    'business_id'          => $lockedBusiness->id,
                    'submitted_by_user_id' => $submittedBy->id,
                    'beneficiary_user_id'  => null,

                    'product_type'         => PlanPaymentProduct::Unlimited,
                    'package_months'       => $months,

                    /*
                     * Start from the full server-authoritative package price.
                     *
                     * ReferralRenewalDiscountService may reduce this by exactly
                     * one valid 150 BDT reward inside this same transaction.
                     */
                    'expected_amount_bdt'  => PlanPackages::priceBdt(
                        $months,
                    ),

                    /*
                     * Model casts encrypt both sensitive plaintext
                     * values before they reach the database.
                     */
                    'trx_id'               => $trxId,
                    'trx_id_hash'          => $trxHash,
                    'payer_number'         => $payerNumber,
                ]);

                $payment = $this->renewalDiscounts
                    ->reserveForPayment(
                        $lockedBusiness,
                        $payment,
                    );

/*
 * A referral reward is optional.
 *
 * If this payment reserved one, record that state transition
 * against the reward itself. The tenant user submitting the
 * renewal is the actor who caused the reservation.
 */
                $reservedReward = ReferralReward::query()
                    ->where(
                        'reserved_for_payment_request_id',
                        $payment->id,
                    )
                    ->first();

                if ($reservedReward !== null) {
                    $this->auditLogger->record(
                        'referral_reward.reserved',
                        $submittedBy,
                        $lockedBusiness->id,
                        $reservedReward,
                    );
                }

                $this->auditLogger->record(
                    'plan_payment.submitted',
                    $submittedBy,
                    $lockedBusiness->id,
                    $payment,
                );

                return $payment;
            });
        } catch (QueryException $e) {
            /*
             * The global trx_id_hash UNIQUE constraint handles the
             * cross-business concurrency race.
             */
            if (
                PlanPaymentRequest::query()
                ->where('trx_id_hash', $trxHash)
                ->exists()
            ) {
                throw $this->duplicateTrx();
            }

            throw $e;
        }
    }

    public function normalizeTrxId(string $value): string
    {
        return preg_replace(
            '/[^A-Z0-9]/',
            '',
            strtoupper($value),
        ) ?? '';
    }

    public function normalizePayerNumber(string $value): string
    {
        return BangladeshMobileNumber::normalize($value);
    }

    private function assertCanSubmit(
        Business $business,
        User $user,
    ): void {
        /*
         * createUnlimited() reloads and locks this Business row before
         * calling this method, so this is authoritative database state.
         *
         * Direct service calls must not bypass suspended-business rules.
         */
        if ($business->status !== AccountStatus::Active) {
            throw new ApiException(
                'PLAN_PAYMENT_FORBIDDEN',
                'Payments cannot be submitted for a suspended business.',
                403,
            );
        }

        /*
         * Do not rely only on business.context middleware.
         *
         * PaymentRequestService may be invoked directly, so revalidate
         * the underlying user account from authoritative database state.
         */
        $eligibleUser = User::query()
            ->whereKey($user->id)
            ->where(
                'status',
                AccountStatus::Active->value,
            )
            ->whereNotNull(
                'email_verified_at',
            )
            ->lockForUpdate()
            ->first();

        if ($eligibleUser === null) {
            throw new ApiException(
                'PLAN_PAYMENT_FORBIDDEN',
                'Only an active and verified account owner or administrator can submit a plan payment.',
                403,
            );
        }

        /*
         * The user must hold an active Owner or Administrator
         * membership in this exact business.
         *
         * This prevents direct-service cross-business identity mixing.
         */
        $allowed = BusinessMembership::query()
            ->where(
                'business_id',
                $business->id,
            )
            ->where(
                'user_id',
                $user->id,
            )
            ->where(
                'status',
                AccountStatus::Active->value,
            )
            ->whereIn(
                'role',
                [
                    BusinessRole::Owner->value,
                    BusinessRole::Administrator->value,
                ],
            )
            ->exists();

        if (!$allowed) {
            throw new ApiException(
                'PLAN_PAYMENT_FORBIDDEN',
                'Only the account owner or administrator can submit a plan payment.',
                403,
            );
        }
    }

    private function assertPendingSlotAvailable(
        Business $business,
    ): void {
        $pending = PlanPaymentRequest::query()
            ->where('business_id', $business->id)
            ->where('status', PlanPaymentStatus::Pending->value)
            ->count();

        if ($pending >= self::MAX_PENDING) {
            throw new ApiException(
                'PLAN_PAYMENT_PENDING_LIMIT',
                'You already have 2 payments waiting for checking.',
                409,
                [
                    'limit'   => self::MAX_PENDING,
                    'pending' => $pending,
                ],
            );
        }
    }

    private function assertTrxUnused(string $trxHash): void
    {
        if (
            PlanPaymentRequest::query()
            ->where('trx_id_hash', $trxHash)
            ->exists()
        ) {
            throw $this->duplicateTrx();
        }
    }

    private function duplicateTrx(): ApiException
    {
        return new ApiException(
            'PAYMENT_TRX_ALREADY_USED',
            'This TrxID was already submitted.',
            409,
        );
    }
}
