<?php

namespace App\Support\Plans;

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

final class PlanAllowanceService
{
    public function snapshot(
        Business $business,
        int $userId,
        ?DateTimeInterface $at = null,
    ): array {
        $at = $at === null
            ? new DateTimeImmutable('now', new DateTimeZone('UTC'))
            : DateTimeImmutable::createFromInterface($at);

        $subscription = BusinessSubscription::query()
            ->where('business_id', $business->id)
            ->first();

        $tier = PlanResolver::effectiveTier(
            $subscription,
            $at,
        );

        $unlimited = $tier === PlanLimits::UNLIMITED;

        $clock = BusinessClock::for($business);

        $periodStart = $clock
            ->toLocal($at)
            ->modify('first day of this month')
            ->format('Y-m-d');

        $resetsOn = $clock
            ->toLocal($at)
            ->modify('first day of next month')
            ->format('Y-m-d');

        $businessUsage = BusinessMonthlyUsage::query()
            ->where('business_id', $business->id)
            ->where('period_start', $periodStart)
            ->first();

        $userUsage = UserMonthlyUsage::query()
            ->where('business_id', $business->id)
            ->where('user_id', $userId)
            ->where('period_start', $periodStart)
            ->first();

        $senderUsed = (int) (
            $userUsage?->sender_remittances_used ?? 0
        );

        $senderBonus = (int) (
            $userUsage?->sender_remittance_bonus ?? 0
        );

        $managerUsed = (int) (
            $businessUsage?->manager_transactions_used ?? 0
        );

        $managerBonus = (int) (
            $businessUsage?->manager_transaction_bonus ?? 0
        );

        $editsUsed = (int) (
            $businessUsage?->edits_used ?? 0
        );

        $familyUsed = SenderRecipient::withoutGlobalScopes()
            ->where('business_id', $business->id)
            ->where('owner_user_id', $userId)
            ->whereNull('deleted_at')
            ->count();

        $customersUsed = Customer::withoutGlobalScopes()
            ->where('business_id', $business->id)
            ->whereNull('deleted_at')
            ->count();

        $senderLimit = PlanLimits::FREE_SENDER_REMITTANCES
            + $senderBonus;

        $managerLimit = PlanLimits::FREE_MANAGER_TRANSACTIONS
            + $managerBonus;

        return [
            'tier' => $tier,

            'access_source' => $subscription?->access_source,

            'access_starts_at' => $this->utcIso(
                $subscription?->access_starts_at,
            ),

            'access_ends_at' => $this->utcIso(
                $subscription?->access_ends_at,
            ),

            'entitlements' => [
                'monthly_pdf' => $unlimited,
            ],

            'allowances' => [
                'sender_remittances' => $this->monthlyAllowance(
                    scope: 'user',
                    limit: $unlimited ? null : $senderLimit,
                    used: $senderUsed,
                    bonus: $senderBonus,
                    resetsOn: $resetsOn,
                ),

                'family_members' => $this->activeAllowance(
                    scope: 'user',
                    limit: $unlimited
                        ? null
                        : PlanLimits::FREE_FAMILY_MEMBERS,
                    used: $familyUsed,
                ),

                'manager_transactions' => $this->monthlyAllowance(
                    scope: 'business',
                    limit: $unlimited ? null : $managerLimit,
                    used: $managerUsed,
                    bonus: $managerBonus,
                    resetsOn: $resetsOn,
                ),

                'customers' => $this->activeAllowance(
                    scope: 'business',
                    limit: $unlimited
                        ? null
                        : PlanLimits::FREE_CUSTOMERS,
                    used: $customersUsed,
                ),

                'edits' => $this->monthlyAllowance(
                    scope: 'business',
                    limit: $unlimited
                        ? null
                        : PlanLimits::FREE_EDITS,
                    used: $editsUsed,
                    bonus: 0,
                    resetsOn: $resetsOn,
                ),
            ],
        ];
    }

    private function monthlyAllowance(
        string $scope,
        ?int $limit,
        int $used,
        int $bonus,
        string $resetsOn,
    ): array {
        return [
            'scope' => $scope,
            'type' => 'monthly',
            'limit' => $limit,
            'used' => $used,
            'remaining' => $limit === null
                ? null
                : max(0, $limit - $used),
            'bonus' => $bonus,
            'resets_on' => $resetsOn,
        ];
    }

    private function activeAllowance(
        string $scope,
        ?int $limit,
        int $used,
    ): array {
        return [
            'scope' => $scope,
            'type' => 'active',
            'limit' => $limit,
            'used' => $used,
            'remaining' => $limit === null
                ? null
                : max(0, $limit - $used),
            'resets_on' => null,
        ];
    }

    private function utcIso(
        ?DateTimeInterface $value,
    ): ?string {
        if ($value === null) {
            return null;
        }

        return DateTimeImmutable::createFromInterface($value)
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s\Z');
    }
}