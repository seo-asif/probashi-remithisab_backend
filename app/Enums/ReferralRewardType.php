<?php

namespace App\Enums;

enum ReferralRewardType: string
{
    case UnlimitedDays = 'unlimited_days';
    case RenewalDiscountBdt = 'renewal_discount_bdt';
}