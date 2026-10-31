<?php

namespace App\Support\Plans;

use App\Models\BusinessSubscription;
use App\Models\ManagerSubscription;
use App\Models\SenderSubscription;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Resolves the plan that is effective at a specific instant.
 *
 * Rules:
 * - Missing subscription row = Free.
 * - Unknown/non-Unlimited tier = Free.
 * - Unlimited before its start time = Free.
 * - Unlimited at/after its expiry time = Free.
 * - Otherwise Unlimited is active.
 *
 * Trial is an access source, not a separate quota tier. A trial can therefore
 * grant Unlimited access while it is active without duplicating quota rules.
 */
final class PlanResolver
{
    public static function effectiveTier(
        ?BusinessSubscription $subscription,
        ?DateTimeInterface $at = null,
    ): string {
        if ($subscription === null) {
            return PlanLimits::FREE;
        }

        if ($subscription->tier !== PlanLimits::UNLIMITED) {
            return PlanLimits::FREE;
        }

        $now = $at === null
        ? new DateTimeImmutable('now', new DateTimeZone('UTC'))
        : DateTimeImmutable::createFromInterface($at);

        if (
            $subscription->access_starts_at !== null
            && $subscription->access_starts_at->getTimestamp() > $now->getTimestamp()
        ) {
            return PlanLimits::FREE;
        }

        if (
            $subscription->access_ends_at !== null
            && $subscription->access_ends_at->getTimestamp() <= $now->getTimestamp()
        ) {
            return PlanLimits::FREE;
        }

        return PlanLimits::UNLIMITED;
    }

    public static function isUnlimited(
        ?BusinessSubscription $subscription,
        ?DateTimeInterface $at = null,
    ): bool {
        return self::effectiveTier($subscription, $at) === PlanLimits::UNLIMITED;
    }

    public static function effectiveSenderTier(
        ?SenderSubscription $subscription,
        ?DateTimeInterface $at = null,
    ): string {
        return self::effectivePaidTier(
            $subscription,
            PlanLimits::SENDER_PLUS,
            $at,
        );
    }

    public static function effectiveManagerTier(
        ?ManagerSubscription $subscription,
        ?DateTimeInterface $at = null,
    ): string {
        return self::effectivePaidTier(
            $subscription,
            PlanLimits::MANAGER_PRO,
            $at,
        );
    }

    private static function effectivePaidTier(
        SenderSubscription|ManagerSubscription|null $subscription,
        string $paidTier,
        ?DateTimeInterface $at,
    ): string {
        if ($subscription === null || $subscription->tier !== $paidTier) {
            return PlanLimits::FREE;
        }

        $now = $at === null
        ? new DateTimeImmutable('now', new DateTimeZone('UTC'))
        : DateTimeImmutable::createFromInterface($at);

        if (
            $subscription->access_starts_at !== null
            && $subscription->access_starts_at->getTimestamp() > $now->getTimestamp()
        ) {
            return PlanLimits::FREE;
        }

        if (
            $subscription->access_ends_at !== null
            && $subscription->access_ends_at->getTimestamp() <= $now->getTimestamp()
        ) {
            return PlanLimits::FREE;
        }

        return $paidTier;
    }

    private function __construct() {}
}
