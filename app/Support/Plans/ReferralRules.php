<?php

namespace App\Support\Plans;

final class ReferralRules
{
    /**
     * Maximum number of friends who can qualify for rewards
     * for one inviter business/account.
     */
    public const MAX_QUALIFYING_REFERRALS = 5;

    /**
     * A qualifying referral rewards an inviter whose underlying
     * plan is Free with seven days of Unlimited access.
     *
     * The referred friend also receives a seven-day trial,
     * subject to the normal one-time trial rules.
     */
    public const FREE_REWARD_DAYS = 7;

    /**
     * A qualifying referral rewards an inviter whose underlying
     * entitlement is paid Unlimited with this discount on one
     * future renewal.
     *
     * Rewards may not be stacked on the same renewal.
     */
    public const PAID_RENEWAL_DISCOUNT_BDT = 150;

    private function __construct()
    {
    }
}