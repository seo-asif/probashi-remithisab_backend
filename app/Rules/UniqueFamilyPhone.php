<?php

namespace App\Rules;

use App\Support\Sender\SenderValidation;
use App\Support\Validation\Reject;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\DB;

/**
 * The app does not allow two family members with the same contact number. The check is private to
 * the user: only THEIR OWN active family members are compared. Uses the sender_recipients table,
 * so the requests add it only for real requests, never in the rule-only tests.
 */
class UniqueFamilyPhone implements ValidationRule
{
    public function __construct(
        private int $businessId,
        private int $ownerUserId,
        private ?string $ignoreUuid = null,
    ) {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!is_string($value) || ($key = SenderValidation::phoneKey($value)) === '') {
            return;
        }

        $duplicate = DB::table('sender_recipients')
            ->where('business_id', $this->businessId)
            ->where('owner_user_id', $this->ownerUserId)
            ->whereNull('deleted_at')
            ->where('phone_normalized', $key)
            ->when($this->ignoreUuid, fn ($query, $uuid) => $query->where('uuid', '!=', $uuid))
            ->first(['name']);

        if ($duplicate) {
            $fail(Reject::msg('phone_duplicate', 'A family member already uses this contact number: ' . $duplicate->name));
        }
    }
}
