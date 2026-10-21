<?php

namespace App\Support\Plans;

use App\Enums\AccountStatus;
use App\Enums\PlanPaymentProduct;
use App\Enums\PlanPaymentStatus;
use App\Exceptions\ApiException;
use App\Models\Business;
use App\Models\BusinessReferral;
use App\Models\PlanPaymentRequest;
use App\Models\PlatformAdmin;
use Illuminate\Support\Facades\DB;
use LogicException;

final class PaymentApprovalService
{
    public function __construct(
        private PaidAccessService $paidAccess,
        private ReferralQualificationService $referralQualification,
        private ReferralRenewalDiscountService $renewalDiscounts,
    ) {
    }

    public function approve(
        PlanPaymentRequest $request,
        PlatformAdmin $admin,
    ): PlanPaymentRequest {
        /*
         * The manual retry handles the narrow race where a referral
         * is claimed between our initial identity read and acquisition
         * of the referred-business mutex.
         *
         * DB::transaction(..., 3) separately handles database deadlocks.
         */
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $approved = DB::transaction(function () use (
                $request,
                $admin,
            ): ?PlanPaymentRequest {
                $lockedAdmin = $this->lockActiveAdmin(
                    $admin->id,
                );

                /*
                 * Always obtain the authoritative business ID from
                 * the stored payment request.
                 */
                $identity = PlanPaymentRequest::query()
                    ->select([
                        'id',
                        'business_id',
                    ])
                    ->findOrFail($request->id);

                $referredBusinessId =
                (int) $identity->business_id;

                /*
                 * This is only an observation.
                 *
                 * We validate it again after acquiring the business
                 * mutexes. If it changed, this transaction does no
                 * mutation and the outer loop retries.
                 */
                $observedInviterId =
                $this->referralInviterId(
                    $referredBusinessId,
                );

                $business =
                $this->lockApprovalBusinesses(
                    $referredBusinessId,
                    $observedInviterId,
                );

                /*
                 * A referral was created while approval was acquiring
                 * its locks. Release this transaction and retry with
                 * the new authoritative lock set.
                 */
                if ($business === null) {
                    return null;
                }

                $payment = PlanPaymentRequest::query()
                    ->whereKey($request->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                /*
                 * An already-approved payment is an idempotent retry.
                 *
                 * Never reapply entitlement or use an approval retry to
                 * qualify/backfill a referral.
                 */
                if (
                    $payment->status
                    === PlanPaymentStatus::Approved
                ) {
                    if ($payment->applied_at === null) {
                        throw new ApiException(
                            'PLAN_PAYMENT_STATE_INVALID',
                            'This payment is approved but its entitlement was not recorded as applied.',
                            409,
                        );
                    }

                    return $payment->refresh();
                }

                if (
                    $payment->status
                    !== PlanPaymentStatus::Pending
                ) {
                    throw new ApiException(
                        'PLAN_PAYMENT_ALREADY_DECIDED',
                        'This payment request has already been decided.',
                        409,
                    );
                }

                $this->assertCleanPendingState(
                    $payment,
                );

                /*
                 * Apply the purchased entitlement first.
                 *
                 * It is still uncommitted because this entire operation
                 * remains inside the same database transaction.
                 */
                $this->applyEntitlement(
                    $payment,
                    $business,
                );

                $decidedAt = now('UTC');

                $payment->forceFill([
                    'status'                       =>
                    PlanPaymentStatus::Approved,

                    'decided_by_platform_admin_id' =>
                    $lockedAdmin->id,

                    'decision_reason'              =>
                    null,

                    'decided_at'                   =>
                    $decidedAt,

                    'applied_at'                   =>
                    $decidedAt,
                ])->save();

                /*
                 * A renewal discount is consumed only after the payment has
                 * successfully transitioned to Approved.
                 *
                 * This remains inside the same transaction as entitlement
                 * activation and payment approval.
                 */
                $this->renewalDiscounts
                    ->applyReservedForApprovedPayment(
                        $business,
                        $payment,
                    );
                /*
                 * Qualification deliberately happens only after the
                 * payment has authoritative Approved + applied state.
                 *
                 * Because this is still the same outer transaction,
                 * failure here rolls back:
                 *
                 * - paid entitlement activation;
                 * - payment approval;
                 * - referral qualification;
                 * - referral reward.
                 */
                if (
                    $payment->product_type
                    === PlanPaymentProduct::Unlimited
                ) {
                    $this->referralQualification
                        ->qualifyApprovedUnlimitedPayment(
                            $payment,
                        );
                }

                return $payment->refresh();
            }, 3);

            if ($approved !== null) {
                return $approved;
            }
        }

        throw new LogicException(
            'Referral relationship changed repeatedly during payment approval.'
        );
    }

    public function reject(
        PlanPaymentRequest $request,
        PlatformAdmin $admin,
        string $reason,
    ): PlanPaymentRequest {
        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > 255) {
            throw new ApiException(
                'PLAN_PAYMENT_REJECTION_REASON_INVALID',
                'Enter a rejection reason of 1 to 255 characters.',
                422,
            );
        }

        return DB::transaction(function () use (
            $request,
            $admin,
            $reason,
        ) {
            $admin = $this->lockActiveAdmin(
                $admin->id,
            );

            /*
             * Read the authoritative business ID from the stored
             * payment request.
             */
            $identity = PlanPaymentRequest::query()
                ->select([
                    'id',
                    'business_id',
                ])
                ->findOrFail($request->id);

            /*
             * Use the business row as the stable mutex before locking
             * the payment and any referral reward.
             */
            $business = Business::query()
                ->whereKey($identity->business_id)
                ->lockForUpdate()
                ->firstOrFail();

            $payment = PlanPaymentRequest::query()
                ->whereKey($request->id)
                ->lockForUpdate()
                ->firstOrFail();

            /*
             * Repeating the same rejection is idempotent.
             *
             * The original Pending -> Rejected transition already
             * released any referral reward reservation.
             */
            if (
                $payment->status
                === PlanPaymentStatus::Rejected
            ) {
                if ($payment->applied_at !== null) {
                    throw new ApiException(
                        'PLAN_PAYMENT_STATE_INVALID',
                        'This rejected payment has inconsistent entitlement state.',
                        409,
                    );
                }

                return $payment->refresh();
            }

            /*
             * Approved payments and any other decided state cannot
             * subsequently be rejected.
             */
            if (
                $payment->status
                !== PlanPaymentStatus::Pending
            ) {
                throw new ApiException(
                    'PLAN_PAYMENT_ALREADY_DECIDED',
                    'This payment request has already been decided.',
                    409,
                );
            }

            $this->assertCleanPendingState(
                $payment,
            );

            $payment->forceFill([
                'status'                       =>
                PlanPaymentStatus::Rejected,

                'decided_by_platform_admin_id' =>
                $admin->id,

                'decision_reason'              =>
                $reason,

                'decided_at'                   =>
                now('UTC'),

                'applied_at'                   =>
                null,
            ])->save();

            /*
             * A rejected renewal never consumes its referral discount.
             *
             * Release the reservation inside this same transaction.
             * If release fails, the payment rejection is rolled back too.
             */
            $this->renewalDiscounts
                ->releaseReservedForRejectedPayment(
                    $business,
                    $payment,
                );

            return $payment->refresh();
        }, 3);
    }

    private function referralInviterId(
        int $referredBusinessId,
    ): ?int {
        $inviterId = BusinessReferral::query()
            ->where(
                'referred_business_id',
                $referredBusinessId,
            )
            ->value('inviter_business_id');

        return $inviterId === null
        ? null
        : (int) $inviterId;
    }

/**
 * Lock every business that approval may mutate.
 *
 * Business IDs are always locked in numeric ascending order.
 *
 * After the locks are acquired, the referral relationship is
 * checked again. ReferralService::claim() itself requires the
 * referred-business mutex, so once we own that row the relationship
 * cannot change underneath this approval transaction.
 *
 * Returning null means the relationship changed while the locks
 * were being acquired and approve() must retry.
 */
    private function lockApprovalBusinesses(
        int $referredBusinessId,
        ?int $observedInviterId,
    ): ?Business {
        $businessIds = [
            $referredBusinessId,
        ];

        if ($observedInviterId !== null) {
            $businessIds[] = $observedInviterId;
        }

        $businessIds = array_values(
            array_unique(
                array_map(
                    'intval',
                    $businessIds,
                ),
            ),
        );

        sort(
            $businessIds,
            SORT_NUMERIC,
        );

        Business::query()
            ->whereIn(
                'id',
                $businessIds,
            )
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        /*
         * Verify that the referred business still exists.
         */
        $business = Business::query()
            ->whereKey($referredBusinessId)
            ->first();

        if ($business === null) {
            throw new LogicException(
                'Payment business disappeared during approval.'
            );
        }

        /*
         * Critical race check.
         *
         * Example:
         *
         * 1. approval initially sees no referral;
         * 2. another transaction finishes claiming an inviter;
         * 3. approval obtains the referred-business lock.
         *
         * In that situation the original lock set is incomplete.
         * Do not continue. Roll back this read-only attempt and retry.
         */
        $confirmedInviterId =
        $this->referralInviterId(
            $referredBusinessId,
        );

        if (
            $confirmedInviterId
            !== $observedInviterId
        ) {
            return null;
        }

        return $business;
    }

    private function assertCleanPendingState(
        PlanPaymentRequest $payment,
    ): void {
        if (
            $payment->decided_by_platform_admin_id !== null
            || $payment->decision_reason !== null
            || $payment->decided_at !== null
            || $payment->applied_at !== null
        ) {
            throw new ApiException(
                'PLAN_PAYMENT_STATE_INVALID',
                'This pending payment has inconsistent decision state.',
                409,
            );
        }
    }

    private function applyEntitlement(
        PlanPaymentRequest $payment,
        Business $business,
    ): void {
        if ($payment->product_type === PlanPaymentProduct::Unlimited) {
            $months = $payment->package_months;

            if (
                $months === null
                || !PlanPackages::isValidMonths($months)
            ) {
                throw new ApiException(
                    'PLAN_PAYMENT_PACKAGE_INVALID',
                    'The stored payment package is invalid.',
                    409,
                );
            }

            /*
             * Recalculate the authoritative amount from server state.
             *
             * Without a reward reservation the amount must equal the full
             * package price.
             *
             * With a valid reward reserved for this exact payment, the amount
             * must equal the package price minus exactly 150 BDT.
             */
            $this->renewalDiscounts
                ->assertAuthoritativePaymentAmount(
                    $business,
                    $payment,
                );

            if ($payment->beneficiary_user_id !== null) {
                throw new ApiException(
                    'PLAN_PAYMENT_STATE_INVALID',
                    'An Unlimited payment cannot have a top-up beneficiary.',
                    409,
                );
            }

            $this->paidAccess->activate(
                $business,
                $months,
            );

            return;
        }

        /*
         * The +20 top-up mechanism already exists, but its BDT
         * purchase price has not been defined.
         *
         * Therefore payment-based top-up approval stays fail-closed.
         */
        if (
            $payment->product_type === PlanPaymentProduct::SenderTopUp
            || $payment->product_type === PlanPaymentProduct::ManagerTopUp
        ) {
            throw new ApiException(
                'PLAN_TOPUP_PRICE_NOT_CONFIGURED',
                'Paid transaction top-ups are not available yet.',
                409,
            );
        }

        throw new ApiException(
            'PLAN_PAYMENT_PRODUCT_INVALID',
            'The stored payment product is invalid.',
            409,
        );
    }

    private function lockActiveAdmin(int $adminId): PlatformAdmin
    {
        $admin = PlatformAdmin::query()
            ->whereKey($adminId)
            ->where(
                'status',
                AccountStatus::Active->value,
            )
            ->lockForUpdate()
            ->first();

        if (!$admin) {
            throw new ApiException(
                'PLATFORM_ADMIN_FORBIDDEN',
                'An active platform administrator is required.',
                403,
            );
        }

        return $admin;
    }
}
