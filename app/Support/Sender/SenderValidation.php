<?php

namespace App\Support\Sender;

use App\Support\Time\BusinessClock;

/** Pure validation helpers shared by the Form Requests, the sync endpoint and the tests. */
final class SenderValidation
{
    /** Same cleaning as Operator Mode: spaces, dashes and brackets are removed from phone numbers. */
    public static function cleanPhone(mixed $phone): mixed
    {
        return is_string($phone) ? preg_replace('/[\s\-()]/', '', $phone) : $phone;
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

    public static function normalizeRemittance(array $data): array
    {
        if (isset($data['notes']) && is_string($data['notes'])) {
            $data['notes'] = trim($data['notes']);
        }
        if (isset($data['currency']) && is_string($data['currency'])) {
            $data['currency'] = strtoupper(trim($data['currency']));
        }

        return $data;
    }

    /**
     * Rules that depend on several fields together (like Operator Mode's "withdrawal charge at most
     * 20%" and "partial payment below the total"). Returns [field => message].
     * On an edit, call it with the stored values merged with the changed ones.
     *
     * Proposed rules (not in the app, please confirm): the charge cannot exceed the amount sent,
     * and the date cannot be in the future in the business timezone.
     */
    public static function crossErrors(array $data, BusinessClock $clock, \DateTimeImmutable $now): array
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
}
