<?php

namespace App\Enums;

enum PlanPaymentProduct: string {
    case Unlimited = 'unlimited';
    case SenderPlus = 'sender_plus';
    case ManagerPro = 'manager_pro';
    case SenderTopUp = 'sender_topup';
    case ManagerTopUp = 'manager_topup';
}
