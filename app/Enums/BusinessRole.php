<?php

namespace App\Enums;

/** Permissions per role arrive with staff accounts (Phase 6). Phase 1 only stores the role. */
enum BusinessRole: string
{
    case Owner = 'owner';
    case Administrator = 'administrator';
    case Operator = 'operator';
    case ReadOnlyStaff = 'read_only';
}
