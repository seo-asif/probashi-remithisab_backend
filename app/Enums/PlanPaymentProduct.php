<?php

namespace App\Enums;

enum PlanPaymentProduct: string {
    case Unlimited = 'unlimited';
    case SenderTopUp = 'sender_topup';
    case ManagerTopUp = 'manager_topup';
}
