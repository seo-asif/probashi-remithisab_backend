<?php

namespace App\Support\Sender;

use App\Support\Money\Dec;
use App\Support\Money\FinancialNumber;
use App\Support\Money\MoneyField;
use App\Support\Time\BusinessClock;

/** Pure validation helpers shared by the Form Requests, the sync endpoint and the tests. */
final class SenderValidation
{
    /** Same cleaning as Operator Mode: spaces, dashes and brackets are removed from phone numbers. */
    public static function cleanPhone(mixed $phone): mixed
    {
        return is_string($phone) ? preg_replace('/[\s\-()]/', '', $phone) : $phone;
    }

    /**
     * Normalize a phone number for duplicate detection.
     * Bangladesh local and international formats share one key.
     */
    public static function phoneKey(mixed $phone): string
    {
        if (!is_string($phone)) {
            return '';
        }

        $phone = self::cleanPhone($phone);

        if ($phone === '') {
            return '';
        }

        // Bangladesh: 01811111111 -> +8801811111111
        if (preg_match('/^01\d{9}$/', $phone)) {
            return '+88' . $phone;
        }

        // Bangladesh: 8801811111111 -> +8801811111111
        if (preg_match('/^8801\d{9}$/', $phone)) {
            return '+' . $phone;
        }

        return $phone;
    }

    public static function normalizeFamily(array $data): array
    {
        if (isset($data['name']) && is_string($data['name'])) {
            $data['name'] = trim($data['name']);
        }
        if (array_key_exists('phone', $data)) {
            $data['phone'] = self::cleanPhone($data['phone']);
        }
        foreach (['relationship', 'notes'] as $key) {
            if (isset($data[$key]) && is_string($data[$key])) {
                $data[$key] = trim($data[$key]);
            }
        }

        return $data;
    }

    public static function normalizeRecipient(array $data): array
    {
        return self::normalizeFamily($data);
    }

    public static function normalizeRemittance(array $data): array
    {
        if (isset($data['notes']) && is_string($data['notes'])) {
            $data['notes'] = trim($data['notes']);
        }
        if (isset($data['currency']) && is_string($data['currency'])) {
            $data['currency'] = strtoupper(trim($data['currency']));
        }

        // Normalize payment method names to canonical values.
        if (isset($data['method']) && is_string($data['method'])) {
            $method = trim($data['method']);

            $data['method'] = match (strtolower($method)) {
                'bkash'  => 'bKash',
                'nagad'  => 'Nagad',
                'rocket' => 'Rocket',
                'bank'   => 'Bank',
                'other'  => 'Other',
                default  => $method,
            };
        }

        return $data;
    }

    public static function parseLocalDateTime(
        mixed $text,
        BusinessClock $clock
    ): ?\DateTimeImmutable {
        if (!is_string($text)) {
            return null;
        }

        $text = trim($text);

        if (!preg_match(
            '/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(?::\d{2})?$/',
            $text
        )) {
            return null;
        }

        try {
            $date = $clock->parseLocal($text);

            $normalizedInput = str_replace('T', ' ', $text);

            $format = strlen($normalizedInput) === 16
            ? 'Y-m-d H:i'
            : 'Y-m-d H:i:s';

            if ($date->format($format) !== $normalizedInput) {
                return null;
            }

            return $date;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Rules that depend on several fields together (like Operator Mode's "withdrawal charge at most
     * 20%" and "partial payment below the total"). Returns [field => message].
     * On an edit, call it with the stored values merged with the changed ones.
     *
     * Proposed rules (not in the app, please confirm): the charge cannot exceed the amount sent,
     * and the date cannot be in the future in the business timezone.
     */
    public static function crossErrors(array $data, BusinessClock $clock, \DateTimeImmutable $now) : array
    {
        $errors = [];

        if (isset($data['amount'], $data['charge']) && $data['charge'] !== '') {
            if (bccomp(SenderCalculator::dec($data['charge'], 2), SenderCalculator::dec($data['amount'], 2), 2) > 0) {
                $errors['charge'] = 'The transfer charge cannot be more than the amount sent.';
            }
        }

        if (isset($data['remit_date']) && is_string($data['remit_date'])) {
            if ($data['remit_date'] > $clock->toLocal($now)->format('Y-m-d')) {
                $errors['remit_date'] = 'The date cannot be in the future.';
            }
        }

        return $errors;
    }

    /**
     * Validate Sender Mode financial fields.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function moneyErrors(array $money, string $currency = 'FJD'): array
    {
        $errors = [];
        $limitsCurrency = \App\Support\Money\CurrencyRules::supported($currency) ? $currency : 'FJD';

        $amount = $money['amount'] ?? null;
        $rate = $money['rate'] ?? null;
        $charge = $money['charge'] ?? '0';
        $chargeType = $money['charge_type'] ?? null;
        $incentivePct = $money['incentive_pct'] ?? '0';

        // Amount: positive, maximum 2 decimals.
        if ($error = MoneyField::check(
            $amount,
            'amount',
            'an amount',
            \App\Support\Money\CurrencyRules::amountMax($limitsCurrency)
        )) {
            $errors['amount'] = $error;
        }

        // Exchange rate: four decimals for KRW/JPY, two for other currencies.
        if ($error = MoneyField::check(
            $rate,
            'rate',
            'an exchange rate',
            \App\Support\Money\CurrencyRules::rateMax($limitsCurrency),
            decimals: \App\Support\Money\CurrencyRules::rateDecimals($limitsCurrency)
        )) {
            $errors['rate'] = $error;
        }

        // Charge: optional, non-negative, maximum defined by SenderOptions.
        if ($error = MoneyField::check(
            $charge,
            'charge',
            'a valid charge',
            SenderOptions::CHARGE_MAX,
            required: false,
            positive: false
        )) {
            $errors['charge'] = $error;
        }

        // Charge type.
        if (!in_array($chargeType, ['included', 'excluded'], true)) {
            $errors['charge_type'] = [
                'charge_type_invalid',
                'Choose whether the charge is included or excluded.',
            ];
        }

        // Included charge must be strictly less than the amount.
        // Excluded charge may exceed the amount.
        if (
            $chargeType === 'included'
            && !isset($errors['amount'])
            && !isset($errors['charge'])
            && bccomp(
                Dec::of($charge),
                Dec::of($amount),
                2
            ) >= 0
        ) {
            $errors['charge'] = [
                'charge_not_less_than_amount',
                'An included charge must be less than the amount sent.',
            ];
        }

        // Incentive: 0–10%, maximum 2 decimal places.
        if (
            !FinancialNumber::isValid($incentivePct, 2)
        ) {
            $errors['incentive_pct'] = [
                'incentive_invalid',
                'Enter a percentage from 0 to 10 (maximum 2 decimals).',
            ];
        } elseif (
            bccomp(Dec::of($incentivePct), '10', 2) > 0
        ) {
            $errors['incentive_pct'] = [
                'incentive_too_high',
                'Incentive cannot be more than 10%.',
            ];
        }

        return $errors;
    }
}
