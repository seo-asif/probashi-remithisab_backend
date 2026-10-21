<?php

namespace App\Support\Plans;

use App\Enums\AccountStatus;
use App\Models\Business;
use App\Models\BusinessMembership;
use App\Models\BusinessMonthlyUsage;
use App\Models\UserMonthlyUsage;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use LogicException;

final class PlanTopUpService
{
    public const TRANSACTION_BONUS = 20;

    public function grantSender(
        Business $business,
        int $userId,
        ?DateTimeInterface $at = null,
    ): UserMonthlyUsage {
        return DB::transaction(function () use (
            $business,
            $userId,
            $at,
        ): UserMonthlyUsage {
            $business = $this->lockBusiness($business);

            $isActiveMember = BusinessMembership::query()
                ->where('business_id', $business->id)
                ->where('user_id', $userId)
                ->where('status', AccountStatus::Active->value)
                ->exists();

            if (!$isActiveMember) {
                throw new LogicException(
                    'Sender top-up user must be an active member of the business.'
                );
            }

            $instant = $this->instant($at);

            $periodStart = app(PlanQuotaService::class)
                ->periodStart($business, $instant);

            UserMonthlyUsage::query()->firstOrCreate([
                'business_id' => $business->id,
                'user_id' => $userId,
                'period_start' => $periodStart,
            ]);

            $usage = UserMonthlyUsage::query()
                ->where('business_id', $business->id)
                ->where('user_id', $userId)
                ->where('period_start', $periodStart)
                ->lockForUpdate()
                ->firstOrFail();

            $usage->increment(
                'sender_remittance_bonus',
                self::TRANSACTION_BONUS,
            );

            return $usage->refresh();
        });
    }

    public function grantManager(
        Business $business,
        ?DateTimeInterface $at = null,
    ): BusinessMonthlyUsage {
        return DB::transaction(function () use (
            $business,
            $at,
        ): BusinessMonthlyUsage {
            $business = $this->lockBusiness($business);

            $instant = $this->instant($at);

            $periodStart = app(PlanQuotaService::class)
                ->periodStart($business, $instant);

            BusinessMonthlyUsage::query()->firstOrCreate([
                'business_id' => $business->id,
                'period_start' => $periodStart,
            ]);

            $usage = BusinessMonthlyUsage::query()
                ->where('business_id', $business->id)
                ->where('period_start', $periodStart)
                ->lockForUpdate()
                ->firstOrFail();

            $usage->increment(
                'manager_transaction_bonus',
                self::TRANSACTION_BONUS,
            );

            return $usage->refresh();
        });
    }

    private function lockBusiness(
        Business $business,
    ): Business {
        return Business::query()
            ->whereKey($business->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function instant(
        ?DateTimeInterface $at,
    ): DateTimeImmutable {
        if ($at === null) {
            return new DateTimeImmutable(
                'now',
                new DateTimeZone('UTC'),
            );
        }

        return DateTimeImmutable::createFromInterface($at)
            ->setTimezone(new DateTimeZone('UTC'));
    }
}