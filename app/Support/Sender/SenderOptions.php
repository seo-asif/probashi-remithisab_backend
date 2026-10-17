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
    public const NAME_MAX  = 60;    // app: maxlength 60
    public const NOTES_MAX = 100;   // app: maxlength 100 (family and remittance)
    public const PHONE_REGEX = '/^\+?\d{1,18}$/';   // same rule as Operator Mode, after spaces, dashes and brackets are removed

    // Record a Remittance form
    public const CURRENCIES    = ['FJD', 'AUD', 'NZD', 'USD', 'SAR', 'AED', 'GBP', 'EUR']; // the Sender form has no QAR
    public const PAYMENT_MODES = ['bKash', 'Nagad', 'Rocket', 'Bank', 'Other'];

    // The app sets no maximums. These match Operator Mode's remittance limits.
    public const AMOUNT_MIN = '0.01';   // app: min 0.01
    public const AMOUNT_MAX = '10000000';
    public const RATE_MIN   = '0.01';   // app: min 0.01
    public const RATE_MAX   = '10000';
    public const CHARGE_MAX = '10000000';
}
