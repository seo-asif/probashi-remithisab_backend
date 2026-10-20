<?php

namespace App\Support\Plans;

use App\Exceptions\ApiException;
use App\Models\Business;
use App\Models\BusinessMonthlyUsage;
use App\Models\BusinessSubscription;
use App\Models\Customer;
use App\Models\SenderRecipient;
use App\Models\UserMonthlyUsage;
use App\Support\Time\BusinessClock;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use LogicException;

final class PlanQuotaService
{
    /**
     * Consume one Sender remittance slot.
     *
     * Must run inside the SAME transaction that creates the remittance.
     */
    public function consumeSenderRemittance(
        Business $business,
        int $userId,
        ?DateTimeInterface $at = null,
    ): UserMonthlyUsage {
        $this->requireTransaction();

        $business = $this->lockBusiness($business);
        $at = $this->instant($at);
        $periodStart = $this->periodStart($business, $at);

        $usage = $this->lockUserUsage(
            $business->id,
            $userId,
            $periodStart,
        );

        $tier = $this->effectiveTier($business, $at);
        $limit = PlanLimits::FREE_SENDER_REMITTANCES
         + $usage->sender_remittance_bonus;

        if (
            $tier !== PlanLimits::UNLIMITED
            && $usage->sender_remittances_used >= $limit
        ) {
            $this->limitReached(
                'PLAN_SENDER_MONTHLY_LIMIT',
                'Your monthly Sender remittance limit has been reached.',
                $limit,
                $usage->sender_remittances_used,
                $tier,
                $business,
                $at,
            );
        }

        /*
         * Count successful creations even while Unlimited is active.
         *
         * This means that if Unlimited expires during the same calendar
         * month, the Free limit immediately reflects that month's usage.
         */
        $usage->increment('sender_remittances_used');

        return $usage->refresh();
    }

    /**
     * Consume one Manager transaction slot for the whole business.
     */
    public function consumeManagerTransaction(
        Business $business,
        ?DateTimeInterface $at = null,
    ): BusinessMonthlyUsage {
        $this->requireTransaction();

        $business = $this->lockBusiness($business);
        $at = $this->instant($at);
        $periodStart = $this->periodStart($business, $at);

        $usage = $this->lockBusinessUsage(
            $business->id,
            $periodStart,
        );

        $tier = $this->effectiveTier($business, $at);
        $limit = PlanLimits::FREE_MANAGER_TRANSACTIONS
         + $usage->manager_transaction_bonus;

        if (
            $tier !== PlanLimits::UNLIMITED
            && $usage->manager_transactions_used >= $limit
        ) {
            $this->limitReached(
                'PLAN_MANAGER_MONTHLY_LIMIT',
                'Your monthly Manager transaction limit has been reached.',
                $limit,
                $usage->manager_transactions_used,
                $tier,
                $business,
                $at,
            );
        }

        $usage->increment('manager_transactions_used');

        return $usage->refresh();
    }

    /**
     * Consume one shared monthly edit.
     *
     * Sender and Manager use this same business-scoped counter.
     */
    public function consumeEdit(
        Business $business,
        ?DateTimeInterface $at = null,
    ): BusinessMonthlyUsage {
        $this->requireTransaction();

        $business = $this->lockBusiness($business);
        $at = $this->instant($at);
        $periodStart = $this->periodStart($business, $at);

        $usage = $this->lockBusinessUsage(
            $business->id,
            $periodStart,
        );

        $tier = $this->effectiveTier($business, $at);
        $limit = PlanLimits::FREE_EDITS;

        if (
            $tier !== PlanLimits::UNLIMITED
            && $usage->edits_used >= $limit
        ) {
            $this->limitReached(
                'PLAN_EDIT_LIMIT',
                'Your monthly edit limit has been reached.',
                $limit,
                $usage->edits_used,
                $tier,
                $business,
                $at,
            );
        }

        $usage->increment('edits_used');

        return $usage->refresh();
    }

    /**
     * Calendar-month key stored in the database.
     *
     * The instant is converted to the BUSINESS timezone first.
     */

/**
 * Check whether this user may have one more active family member.
 *
 * This is an active-record cap, not a monthly counter.
 * Soft-deleted family members do not consume a slot.
 */
    public function assertFamilyMemberAvailable(
        Business $business,
        int $userId,
        ?DateTimeInterface $at = null,
    ): void {
        $this->requireTransaction();

        $business = $this->lockBusiness($business);
        $at = $this->instant($at);
        $tier = $this->effectiveTier($business, $at);

        if ($tier === PlanLimits::UNLIMITED) {
            return;
        }

        $used = SenderRecipient::withoutGlobalScopes()
            ->where('business_id', $business->id)
            ->where('owner_user_id', $userId)
            ->whereNull('deleted_at')
            ->count();

        $limit = PlanLimits::FREE_FAMILY_MEMBERS;

        if ($used >= $limit) {
            $this->activeLimitReached(
                'PLAN_FAMILY_LIMIT',
                'Your active family member limit has been reached.',
                $limit,
                $used,
                $tier,
            );
        }
    }

/**
 * Check whether this business may have one more active Manager customer.
 *
 * This is an active-record cap, not a monthly counter.
 * Soft-deleted customers do not consume a slot.
 */
    public function assertCustomerAvailable(
        Business $business,
        ?DateTimeInterface $at = null,
    ): void {
        $this->requireTransaction();

        $business = $this->lockBusiness($business);
        $at = $this->instant($at);
        $tier = $this->effectiveTier($business, $at);

        if ($tier === PlanLimits::UNLIMITED) {
            return;
        }

        $used = Customer::withoutGlobalScopes()
            ->where('business_id', $business->id)
            ->whereNull('deleted_at')
            ->count();

        $limit = PlanLimits::FREE_CUSTOMERS;

        if ($used >= $limit) {
            $this->activeLimitReached(
                'PLAN_CUSTOMER_LIMIT',
                'Your active customer limit has been reached.',
                $limit,
                $used,
                $tier,
            );
        }
    }

    public function periodStart(
        Business $business,
        DateTimeInterface $at,
    ): string {
        $local = BusinessClock::for($business)->toLocal($at);

        return $local
            ->modify('first day of this month')
            ->format('Y-m-01');
    }

    private function effectiveTier(
        Business $business,
        DateTimeInterface $at,
    ): string {
        $subscription = BusinessSubscription::query()
            ->where('business_id', $business->id)
            ->first();

        return PlanResolver::effectiveTier($subscription, $at);
    }

    private function lockBusiness(Business $business): Business
    {
        return Business::query()
            ->whereKey($business->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function lockBusinessUsage(
        int $businessId,
        string $periodStart,
    ): BusinessMonthlyUsage {
        BusinessMonthlyUsage::query()->firstOrCreate([
            'business_id'  => $businessId,
            'period_start' => $periodStart,
        ]);

        return BusinessMonthlyUsage::query()
            ->where('business_id', $businessId)
            ->where('period_start', $periodStart)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function lockUserUsage(
        int $businessId,
        int $userId,
        string $periodStart,
    ): UserMonthlyUsage {
        UserMonthlyUsage::query()->firstOrCreate([
            'business_id'  => $businessId,
            'user_id'      => $userId,
            'period_start' => $periodStart,
        ]);

        return UserMonthlyUsage::query()
            ->where('business_id', $businessId)
            ->where('user_id', $userId)
            ->where('period_start', $periodStart)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function limitReached(
        string $code,
        string $message,
        int $limit,
        int $used,
        string $tier,
        Business $business,
        DateTimeInterface $at,
    ): never {
        throw new ApiException(
            $code,
            $message,
            409,
            [
                'limit'     => $limit,
                'used'      => $used,
                'remaining' => max(0, $limit - $used),
                'resets_on' => $this->resetsOn($business, $at),
                'plan'      => $tier,
            ],
        );
    }

    private function activeLimitReached(
        string $code,
        string $message,
        int $limit,
        int $used,
        string $tier,
    ): never {
        throw new ApiException(
            $code,
            $message,
            409,
            [
                'limit'     => $limit,
                'used'      => $used,
                'remaining' => max(0, $limit - $used),
                'plan'      => $tier,
            ],
        );
    }

    private function resetsOn(
        Business $business,
        DateTimeInterface $at,
    ): string {
        return BusinessClock::for($business)
            ->toLocal($at)
            ->modify('first day of next month')
            ->format('Y-m-d');
    }

    private function instant(
        ?DateTimeInterface $at,
    ): DateTimeImmutable {
        if ($at !== null) {
            return DateTimeImmutable::createFromInterface($at);
        }

        return new DateTimeImmutable(
            'now',
            new DateTimeZone('UTC'),
        );
    }

    private function requireTransaction(): void
    {
        if (DB::connection()->transactionLevel() < 1) {
            throw new LogicException(
                'Plan quota consumption must run inside a database transaction.'
            );
        }
    }
}
