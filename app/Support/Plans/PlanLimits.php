<?php

namespace App\Support\Plans;

/**
 * Server-authoritative plan limits.
 *
 * Flutter may display these allowances, but it must never be the authority
 * deciding whether a write is allowed.
 */
final class PlanLimits
{
    public const FREE = 'free';
    public const UNLIMITED = 'unlimited';

    public const FREE_SENDER_REMITTANCES = 20;
    public const FREE_MANAGER_TRANSACTIONS = 20;
    public const FREE_FAMILY_MEMBERS = 20;
    public const FREE_CUSTOMERS = 10;

    private function __construct()
    {
    }
}