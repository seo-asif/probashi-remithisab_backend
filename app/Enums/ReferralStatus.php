<?php

namespace App\Enums;

enum ReferralStatus: string
{
    case Claimed = 'claimed';
    case Qualified = 'qualified';
}