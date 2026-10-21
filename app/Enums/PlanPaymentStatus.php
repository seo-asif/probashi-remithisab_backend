<?php

namespace App\Enums;

enum PlanPaymentStatus: string {
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
}
