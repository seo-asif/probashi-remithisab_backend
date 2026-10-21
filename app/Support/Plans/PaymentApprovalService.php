<?php

namespace App\Support\Plans;

use App\Enums\AccountStatus;
use App\Enums\PlanPaymentProduct;
use App\Enums\PlanPaymentStatus;
use App\Exceptions\ApiException;
use App\Models\Business;
use App\Models\PlanPaymentRequest;
use App\Models\PlatformAdmin;
use Illuminate\Support\Facades\DB;

final class PaymentApprovalService
{
    public function __construct(
        private PaidAccessService $paidAccess,
    ) {
    }

    public function approve(
        PlanPaymentRequest $request,
        PlatformAdmin $admin,
    ): PlanPaymentRequest {
        return DB::transaction(function () use ($request, $admin) {
            $admin = $this->lockActiveAdmin($admin->id);

            /*
             * Read the authoritative business ID from the database,
             * not from the caller's model instance.
             */
            $identity = PlanPaymentRequest::query()
                ->select(['id', 'business_id'])
                ->findOrFail($request->id);

            /*
             * Keep lock order stable:
             *
             * platform admin -> business -> payment request
             *
             * PaidAccessService will lock this same business again
             * inside the existing transaction.
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
             * Successful approval retry:
             * never grant the entitlement twice.
             */
            if ($payment->status === PlanPaymentStatus::Approved) {
                if ($payment->applied_at !== null) {
                    return $payment->refresh();
                }

                throw new ApiException(
                    'PLAN_PAYMENT_STATE_INVALID',
                    'This payment is approved but its entitlement was not recorded as applied.',
                    409,
                );
            }

            if ($payment->status !== PlanPaymentStatus::Pending) {
                throw new ApiException(
                    'PLAN_PAYMENT_ALREADY_DECIDED',
                    'This payment request has already been decided.',
                    409,
                );
            }

            $this->assertCleanPendingState($payment);

            $this->applyEntitlement(
                $payment,
                $business,
            );

            /*
             * Entitlement application and these decision fields are
             * committed atomically. If anything above fails, all of
             * it rolls back.
             */
            $decidedAt = now('UTC');

            $payment->forceFill([
                'status'                       => PlanPaymentStatus::Approved,
                'decided_by_platform_admin_id' => $admin->id,
                'decision_reason'              => null,
                'decided_at'                   => $decidedAt,
                'applied_at'                   => $decidedAt,
            ])->save();

            return $payment->refresh();
        });
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
            $admin = $this->lockActiveAdmin($admin->id);

            $identity = PlanPaymentRequest::query()
                ->select(['id', 'business_id'])
                ->findOrFail($request->id);

            /*
             * Use the same lock order as approve().
             */
            Business::query()
                ->whereKey($identity->business_id)
                ->lockForUpdate()
                ->firstOrFail();

            $payment = PlanPaymentRequest::query()
                ->whereKey($request->id)
                ->lockForUpdate()
                ->firstOrFail();

            /*
             * Repeating the same rejection is harmless.
             */
            if ($payment->status === PlanPaymentStatus::Rejected) {
                if ($payment->applied_at !== null) {
                    throw new ApiException(
                        'PLAN_PAYMENT_STATE_INVALID',
                        'This rejected payment has an invalid applied entitlement state.',
                        409,
                    );
                }

                return $payment->refresh();
            }

            if ($payment->status !== PlanPaymentStatus::Pending) {
                throw new ApiException(
                    'PLAN_PAYMENT_ALREADY_DECIDED',
                    'This payment request has already been decided.',
                    409,
                );
            }

            $this->assertCleanPendingState($payment);

            $payment->forceFill([
                'status'                       => PlanPaymentStatus::Rejected,
                'decided_by_platform_admin_id' => $admin->id,
                'decision_reason'              => $reason,
                'decided_at'                   => now('UTC'),
                'applied_at'                   => null,
            ])->save();

            return $payment->refresh();
        });
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
             * Detect corrupted/tampered payment records before
             * granting paid access.
             */
            $expectedPrice = PlanPackages::priceBdt($months);

            if ($payment->expected_amount_bdt !== $expectedPrice) {
                throw new ApiException(
                    'PLAN_PAYMENT_AMOUNT_MISMATCH',
                    'The stored payment amount does not match the server package price.',
                    409,
                );
            }

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
