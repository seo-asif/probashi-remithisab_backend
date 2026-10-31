<?php

namespace App\Support\Plans;

use App\Exceptions\ApiException;
use App\Models\Business;
use App\Models\BusinessMonthlyUsage;
use App\Models\Customer;
use App\Models\ManagerSubscription;
use App\Models\ManagerTransaction;
use App\Models\SenderRecipient;
use App\Models\SenderRemittance;
use App\Models\SenderSubscription;
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

        $tier = $this->effectiveSenderTier($userId, $at);
        $limit = PlanLimits::FREE_SENDER_REMITTANCES
         + $usage->sender_remittance_bonus;

        if (
            $tier !== PlanLimits::SENDER_PLUS
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

        $tier = $this->effectiveManagerTier($business, $at);
        $limit = PlanLimits::FREE_MANAGER_TRANSACTIONS
         + $usage->manager_transaction_bonus;

        if (
            $tier !== PlanLimits::MANAGER_PRO
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
     * Calendar-month key stored in the database.
     *
     * The instant is converted to the BUSINESS timezone first.
     */

    /**
     * Check whether this user may have one more active family member.
     *
     * This is a lifetime-created-profile cap, not a monthly counter.
     * Archived (soft-deleted) profiles still consume the original slot.
     */
    public function assertFamilyMemberAvailable(
        Business $business,
        int $userId,
        ?DateTimeInterface $at = null,
    ): void {
        $this->requireTransaction();

        $business = $this->lockBusiness($business);
        $at = $this->instant($at);
        $tier = $this->effectiveSenderTier($userId, $at);

        if ($tier === PlanLimits::SENDER_PLUS) {
            return;
        }

        $used = SenderRecipient::withoutGlobalScopes()
            ->where('business_id', $business->id)
            ->where('owner_user_id', $userId)
            ->withTrashed()
            ->count();

        $limit = PlanLimits::FREE_FAMILY_MEMBERS;

        if ($used >= $limit) {
            $this->activeLimitReached(
                'PLAN_FAMILY_LIMIT',
                'Your Free family profile creation limit has been reached.',
                $limit,
                $used,
                $tier,
            );
        }
    }

    /**
     * Check whether this business may have one more active Manager customer.
     *
     * This is a lifetime-created-profile cap, not a monthly counter.
     * Archived (soft-deleted) profiles still consume the original slot.
     */
    public function assertCustomerAvailable(
        Business $business,
        ?DateTimeInterface $at = null,
    ): void {
        $this->requireTransaction();

        $business = $this->lockBusiness($business);
        $at = $this->instant($at);
        $tier = $this->effectiveManagerTier($business, $at);

        if ($tier === PlanLimits::MANAGER_PRO) {
            return;
        }

        $used = Customer::withoutGlobalScopes()
            ->where('business_id', $business->id)
            ->withTrashed()
            ->count();

        $limit = PlanLimits::FREE_CUSTOMERS;

        if ($used >= $limit) {
            $this->activeLimitReached(
                'PLAN_CUSTOMER_LIMIT',
                'Your Free customer profile creation limit has been reached.',
                $limit,
                $used,
                $tier,
            );
        }
    }

    /**
     * Refund an eligible Sender remittance in its ORIGINAL business-local month.
     * The record is locked by the caller; the marker and counter change together.
     * A record can be refunded at most once throughout its lifetime.
     */
    public function refundSenderRemittance(SenderRemittance $record): void
    {
        $this->requireTransaction();

        if (! $this->withinRefundWindow($record)) {
            return;
        }

        $business = $this->lockBusiness(
            Business::query()->findOrFail($record->business_id)
        );
        $period = $this->periodStart($business, $record->created_at);
        $usage = UserMonthlyUsage::query()
            ->where('business_id', $business->id)
            ->where('user_id', $record->owner_user_id)
            ->where('period_start', $period)
            ->lockForUpdate()
            ->first();

        // Legacy or inconsistent missing usage must never become negative.
        if ($usage === null || $usage->sender_remittances_used < 1) {
            return;
        }

        $usage->decrement('sender_remittances_used');
        $record->forceFill(['quota_refunded_at' => now()])->save();
    }

    /**
     * A restored, previously refunded remittance reclaims its original slot.
     * Restoration may fail if the original month has since reached its Free cap.
     * Do not charge the current month merely because restore happens later.
     */
    public function reapplySenderRemittance(SenderRemittance $record): void
    {
        $this->requireTransaction();

        if (! $this->needsReapply($record)) {
            return;
        }

        $business = $this->lockBusiness(
            Business::query()->findOrFail($record->business_id)
        );
        $at = $this->instant(null);
        $period = $this->periodStart($business, $record->created_at);
        $usage = $this->lockUserUsage($business->id, $record->owner_user_id, $period);

        $tier = $this->effectiveSenderTier($record->owner_user_id, $at);
        $limit = PlanLimits::FREE_SENDER_REMITTANCES + $usage->sender_remittance_bonus;
        if ($tier !== PlanLimits::SENDER_PLUS && $usage->sender_remittances_used >= $limit) {
            $this->limitReached(
                'PLAN_SENDER_MONTHLY_LIMIT',
                'Cannot restore: the original Sender monthly allowance is full.',
                $limit,
                $usage->sender_remittances_used,
                $tier,
                $business,
                $record->created_at,
            );
        }

        $usage->increment('sender_remittances_used');
        $record->forceFill(['quota_refund_reapplied_at' => now()])->save();
    }

    /** Refund a Manager transaction in its original business-local month. */
    public function refundManagerTransaction(ManagerTransaction $record): void
    {
        $this->requireTransaction();

        if (! $this->withinRefundWindow($record)) {
            return;
        }

        $business = $this->lockBusiness(
            Business::query()->findOrFail($record->business_id)
        );
        $period = $this->periodStart($business, $record->created_at);
        $usage = BusinessMonthlyUsage::query()
            ->where('business_id', $business->id)
            ->where('period_start', $period)
            ->lockForUpdate()
            ->first();

        if ($usage === null || $usage->manager_transactions_used < 1) {
            return;
        }

        $usage->decrement('manager_transactions_used');
        $record->forceFill(['quota_refunded_at' => now()])->save();
    }

    /** Reverse the prior Manager refund before restoring a transaction. */
    public function reapplyManagerTransaction(ManagerTransaction $record): void
    {
        $this->requireTransaction();

        if (! $this->needsReapply($record)) {
            return;
        }

        $business = $this->lockBusiness(
            Business::query()->findOrFail($record->business_id)
        );
        $at = $this->instant(null);
        $period = $this->periodStart($business, $record->created_at);
        $usage = $this->lockBusinessUsage($business->id, $period);

        $tier = $this->effectiveManagerTier($business, $at);
        $limit = PlanLimits::FREE_MANAGER_TRANSACTIONS + $usage->manager_transaction_bonus;
        if ($tier !== PlanLimits::MANAGER_PRO && $usage->manager_transactions_used >= $limit) {
            $this->limitReached(
                'PLAN_MANAGER_MONTHLY_LIMIT',
                'Cannot restore: the original Manager monthly allowance is full.',
                $limit,
                $usage->manager_transactions_used,
                $tier,
                $business,
                $record->created_at,
            );
        }

        $usage->increment('manager_transactions_used');
        $record->forceFill(['quota_refund_reapplied_at' => now()])->save();
    }

    private function withinRefundWindow(SenderRemittance|ManagerTransaction $record): bool
    {
        if ($record->quota_refunded_at !== null || $record->created_at === null) {
            return false;
        }

        $now = now();

        return $now->greaterThanOrEqualTo($record->created_at)
        && $now->lessThanOrEqualTo($record->created_at->copy()->addSeconds(600));
    }

    private function needsReapply(SenderRemittance|ManagerTransaction $record): bool
    {
        return $record->quota_refunded_at !== null
        && $record->quota_refund_reapplied_at === null;
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

    private function effectiveSenderTier(
        int $userId,
        DateTimeInterface $at,
    ): string {
        $subscription = SenderSubscription::query()
            ->where('user_id', $userId)
            ->first();

        return PlanResolver::effectiveSenderTier($subscription, $at);
    }

    private function effectiveManagerTier(
        Business $business,
        DateTimeInterface $at,
    ): string {
        $subscription = ManagerSubscription::query()
            ->where('business_id', $business->id)
            ->first();

        return PlanResolver::effectiveManagerTier($subscription, $at);
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
            'business_id' => $businessId,
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
            'business_id' => $businessId,
            'user_id' => $userId,
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
                'limit' => $limit,
                'used' => $used,
                'remaining' => max(0, $limit - $used),
                'resets_on' => $this->resetsOn($business, $at),
                'plan' => $tier,
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
                'limit' => $limit,
                'used' => $used,
                'remaining' => max(0, $limit - $used),
                'plan' => $tier,
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
