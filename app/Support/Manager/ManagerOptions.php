<?php

namespace App\Support\Manager;

/**
 * Fixed choices and limits of Remittance Manager mode (রেমিট্যান্স ম্যানেজার). Source: Money Rules v1, section 3,
 * and app v1.1.270 for customer fields.
 */
final class ManagerOptions
{
    // customers
    public const CUSTOMER_NAME_MIN = 2;
    public const CUSTOMER_NAME_MAX = 20;
    public const ADDRESS_MAX       = 100;
    public const NOTES_MAX         = 100;
    public const PHONE_REGEX       = '/^\+?\d{1,18}$/';
    public const WALLETS_MAX       = 5;
    public const BANKS_MAX         = 10;
    public const RESTORE_DAYS      = 30;

    // customer transactions
    public const STATUS_PENDING  = 'currency_pending';   // গ্রাহক এখনও টাকা দেয়নি
    public const STATUS_RECEIVED = 'currency_received';  // টাকা পেয়েছি, পাঠানো বাকি
    public const STATUS_SENT     = 'bdt_sent';           // টাকা পাঠানো হয়েছে
    public const STATUSES        = [self::STATUS_PENDING, self::STATUS_RECEIVED, self::STATUS_SENT];

    public const RECEIVE_METHODS = ['Bkash', 'Nagad', 'Rocket', 'Bank', 'Cash', 'Other Payment Method'];
    public const WALLET_METHODS  = ['Bkash', 'Nagad', 'Rocket'];
    public const NO_TARGET_METHODS = ['Cash', 'Other Payment Method'];

    public const AMOUNT_MAX             = '10000';
    public const RATE_MAX               = '400';
    public const COMMISSION_MAX_PERCENT = '20';   // of the amount
    public const WITHDRAWAL_MAX_PERCENT = '20';   // of base_bdt

    // bank remittances
    public const REMIT_METHODS    = ['Bank', 'Account', 'Bkash', 'Nagad', 'Rocket', 'Other'];
    public const OTHER            = 'Other';      // the operator types the BDT total ("Direct Cash" in old data)
    public const REMIT_AMOUNT_MAX = '10000000';
    public const CHARGE_INCLUDED  = 'included';
    public const CHARGE_EXCLUDED  = 'excluded';
    public const CHARGE_TYPES     = [self::CHARGE_INCLUDED, self::CHARGE_EXCLUDED];
    public const INCENTIVE_MAX    = '10';
    public const BDT_MAX          = '1000000000';

    /** Case-insensitive match to the canonical value; also accepts the old "Direct Cash" and display labels. */
    public static function canonical(mixed $value, array $allowed): mixed
    {
        if (!is_string($value)) {
            return $value;
        }

        $v = trim($value);
        $aliases = ['direct cash' => 'Other', 'currency pending' => self::STATUS_PENDING, 'currency received' => self::STATUS_RECEIVED, 'bdt sent' => self::STATUS_SENT];

        if (isset($aliases[strtolower($v)]) && in_array($aliases[strtolower($v)], $allowed, true)) {
            return $aliases[strtolower($v)];
        }

        foreach ($allowed as $option) {
            if (strcasecmp($option, $v) === 0) {
                return $option;
            }
        }

        return $v;
    }
}
