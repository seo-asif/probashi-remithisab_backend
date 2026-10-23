<?php

namespace App\Support\Sender;

/** The fixed choices and limits of Sender Mode, taken from app v1.0.201 (and Operator Mode limits where the app sets none). */
final class SenderOptions
{
    // Family & Recipients form
    public const RELATIONSHIPS = [
        'Mother', 'Father', 'Wife / Husband', 'Son / Daughter', 'Brother',
        'Sister', 'Other relative', 'Friend', 'Other',
    ];
    public const NAME_MIN = 2;
    public const NAME_MAX = 20; // current prototype: maxlength 20
    public const NOTES_MAX = 100; // app: maxlength 100 (family and remittance)
    public const PHONE_REGEX = '/^\+?\d{1,18}$/'; // same rule as Operator Mode, after spaces, dashes and brackets are removed

    // Record a Remittance form
    public const CURRENCIES = \App\Support\Money\CurrencyRules::CURRENCIES;
    public const PAYMENT_MODES = ['bKash', 'Nagad', 'Rocket', 'Bank', 'Other'];

    // Sender Mode limits from the current prototype.
    public const AMOUNT_MIN = '0.01';
    public const AMOUNT_MAX = '10000';
    public const RATE_MIN = '0.01';
    public const RATE_MAX = '400';
    public const CHARGE_MAX = '10000';
    // Phase 3 recipient types
    public const RECIPIENT_SELF = 'self';
    public const RECIPIENT_FAMILY = 'family';

    public const RECIPIENT_TYPES = [
        self::RECIPIENT_SELF,
        self::RECIPIENT_FAMILY,
    ];

// Phase 3 charge types
    public const CHARGE_INCLUDED = 'included';
    public const CHARGE_EXCLUDED = 'excluded';

    public const CHARGE_TYPES = [
        self::CHARGE_INCLUDED,
        self::CHARGE_EXCLUDED,
    ];

// Phase 3 remittance methods
    public const METHODS = [
        'bKash',
        'Nagad',
        'Rocket',
        'Bank',
        'Other',
    ];

    // Savings goals
    public const GOAL_NAME_MAX = 30;
    public const GOAL_TARGET_MAX = '10000000';

// Remittance purposes
    public const PURPOSES = [
        'house',
    ];
}
