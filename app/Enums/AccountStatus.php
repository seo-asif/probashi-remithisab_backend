<?php

namespace App\Enums;

/** Used for users, businesses and memberships. */
enum AccountStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
}
