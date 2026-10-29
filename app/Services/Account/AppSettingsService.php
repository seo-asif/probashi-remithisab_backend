<?php

namespace App\Services\Account;

use App\Models\Business;
use App\Models\User;
use App\Models\UserAppSetting;
use App\Support\Money\CurrencyRules;
use Illuminate\Support\Facades\DB;

/** Per-user app preferences. The device-only app-lock PIN is never stored here. */
final class AppSettingsService
{
    public const FEATURES = [
        'work' => false,
        'goals' => false,
        'remind' => false,
        'auto' => true,
    ];

    public const REMINDERS = [
        'time' => '19:00',
        'confirm' => true,
        'pay' => false,
        'payType' => 'monthly',
        'payDay' => 1,
        'payWd' => 5,
        'payLast' => null,
        'loan' => false,
        'loanDay' => 5,
        'mgr' => true,
    ];

    public function snapshot(User $user, ?Business $business = null): array
    {
        $row = UserAppSetting::query()->where('user_id', $user->getKey())->first();
        $businessCurrency = $business?->default_currency;
        $fallback = CurrencyRules::supported($businessCurrency) ? $businessCurrency : 'FJD';

        return [
            // Null means the onboarding question has not yet been answered.
            'language' => $row?->language,
            'mode' => $row?->mode,
            'default_currency' => $row?->default_currency ?? $fallback,
            'is_student' => $row?->is_student,
            'intro_seen' => $row?->intro_seen ?? false,
            'features' => array_replace(self::FEATURES, $row?->features ?? []),
            'reminders' => array_replace(self::REMINDERS, $row?->reminders ?? []),
        ];
    }

    /** Partial updates merge nested preferences without resetting other switches. */
    public function save(User $user, array $input, ?Business $business = null): array
    {
        DB::transaction(function () use ($user, $input): void {
            // Lock the parent user to serialize the first settings row creation.
            User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            $row = UserAppSetting::query()->firstOrNew(['user_id' => $user->getKey()]);

            foreach (['language', 'mode', 'default_currency', 'is_student', 'intro_seen'] as $field) {
                if (array_key_exists($field, $input)) {
                    $row->{$field} = $input[$field];
                }
            }

            if (array_key_exists('features', $input)) {
                $features = array_replace(self::FEATURES, $row->features ?? [], $input['features']);
                // Explicitly toggling a feature takes precedence over automatic enablement.
                if (!array_key_exists('auto', $input['features'])
                    && array_intersect(['work', 'goals', 'remind'], array_keys($input['features']))) {
                    $features['auto'] = false;
                }
                $row->features = $features;
            }

            if (array_key_exists('reminders', $input)) {
                $row->reminders = array_replace(self::REMINDERS, $row->reminders ?? [], $input['reminders']);
            }

            $row->save();
        });

        return $this->snapshot($user, $business);
    }
}
