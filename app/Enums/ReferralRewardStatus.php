<?php

namespace App\Enums;

enum ReferralRewardStatus: string
{
    case Available = 'available';
    case Reserved = 'reserved';
    case Applied = 'applied';
}