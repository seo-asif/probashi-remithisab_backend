<?php

namespace App\Services\Manager;

use App\Models\ManagerRemittance;
use App\Support\Manager\ManagerCalculator;
use App\Support\Manager\ManagerOptions as O;
use App\Support\Money\Dec;
use App\Support\Money\FinancialNumber;
use App\Support\Money\MoneyField;
use App\Support\Records\MoneyAudit;
use App\Support\Records\RecordId;
use App\Support\Records\RecordTime;
use App\Support\Sender\SenderOptions;
use App\Support\Tenancy\CurrentBusiness;
use App\Support\Usage\EditPolicy;
use App\Support\Validation\Reject;
use Illuminate\Support\Facades\DB;

/**
 * Manager bank remittances (Money Rules 3.3), each save checked as a whole and logged.
 * Charge (from app v1.1.270): may not exceed the amount; an included charge must be less than the amount.
 */
final class RemittanceService
{
   public function __construct(
    private CurrentBusiness $current,
) {
}

    /** @return array{0: ManagerRemittance, 1: bool} */
    public function create(array $input): array
    {
        if ($existing = RecordId::existing(ManagerRemittance::class, $input['id'] ?? null)) {
            return [$existing, false];
        }

        $input['currency'] ??= $this->current->get()->default_currency ?? 'FJD';
        $values = $this->checked($input, null);

        $remittance = DB::transaction(function () use ($input, $values) {
            $r = ManagerRemittance::create([...$values, 'uuid' => RecordId::make($input['id'] ?? null), 'user_id' => $this->current->userId()]);
            MoneyAudit::record('created', $r, [], MoneyAudit::values($r));

            return $r;
        });

        return [$remittance->refresh(), true];
    }

    public function update(
    ManagerRemittance $r,
    array $input,
): ManagerRemittance {
    $values = $this->checked($input, $r);

    return DB::transaction(function () use ($r, $values) {
        $old = MoneyAudit::values($r);

        $r->fill($values);

        $changed = EditPolicy::hasRealChange(
            $old,
            MoneyAudit::values($r),
        );

        if (!$changed) {
            return $r->refresh();
        }


        $r->save();

        MoneyAudit::record(
            'updated',
            $r,
            $old,
            MoneyAudit::values($r),
        );

        return $r->refresh();
    });
}
    public function delete(ManagerRemittance $r): void
    {
        DB::transaction(function () use ($r) {
            $r->delete();
            MoneyAudit::record('deleted', $r);
        });
    }

    public function restore(ManagerRemittance $r): ManagerRemittance
    {
        if ($r->trashed()) {
            DB::transaction(function () use ($r) {
                $r->restore();
                MoneyAudit::record('restored', $r);
            });
        }

        return $r->refresh();
    }

    private function checked(array $input, ?ManagerRemittance $stored): array
    {
        $pick   = fn (string $k, mixed $d = null) => array_key_exists($k, $input) ? $input[$k] : ($stored?->{$k} ?? $d);
        $errors = [];

        $method = $pick('method');
        if (!in_array($method, O::REMIT_METHODS, true)) {
            $errors['method'] = ['method_invalid', 'Choose a method: Bank, Account, Bkash, Nagad, Rocket or Other.'];
        }
        $currency = $pick('currency');
        if (!in_array($currency, SenderOptions::CURRENCIES, true)) {
            $errors['currency'] = ['currency_invalid', 'Choose a currency from the list.'];
        }

        $other      = $method === O::OTHER;
        $chargeType = $pick('charge_type', O::CHARGE_EXCLUDED);
        $incentive  = (bool) $pick('incentive', false);
        $typed      = null;

        if ($other) {
            // only a typed BDT total; switching an existing record to Other needs a newly typed total
            $typed = ($stored && $stored->method !== O::OTHER) ? ($input['remit_bdt'] ?? null) : $pick('remit_bdt');
            if ($e = MoneyField::check($typed, 'remit_bdt', 'the total BDT', O::BDT_MAX)) {
                $errors['remit_bdt'] = $e;
            }
            [$amount, $rate, $charge, $pct, $incentive, $chargeType] = ['0.00', '0.00', '0.00', '0.00', false, O::CHARGE_EXCLUDED];
        } else {
            [$amount, $rate, $charge, $pct] = [$pick('amount'), $pick('rate'), $pick('charge'), $incentive ? $pick('incentive_pct') : '0'];

            foreach ([['amount', 'an amount', O::REMIT_AMOUNT_MAX, $amount], ['rate', 'an exchange rate', O::RATE_MAX, $rate]] as [$f, $label, $max, $v]) {
                if ($e = MoneyField::check($v, $f, $label, $max)) {
                    $errors[$f] = $e;
                }
            }
            if (!in_array($chargeType, O::CHARGE_TYPES, true)) {
                $errors['charge_type'] = ['charge_type_invalid', 'Choose whether the charge is included or excluded.'];
            }
            if ($e = MoneyField::check($charge, 'charge', 'a valid charge', O::REMIT_AMOUNT_MAX, required: false, positive: false)) {
                $errors['charge'] = $e;
            } elseif (!isset($errors['amount'])) {
                if (bccomp(Dec::of($charge), Dec::of($amount), 2) > 0) {
                    $errors['charge'] = ['charge_more_than_amount', 'Charge cannot be more than the amount sent.'];
                } elseif ($chargeType === O::CHARGE_INCLUDED && bccomp(Dec::of($charge), Dec::of($amount), 2) >= 0) {
                    $errors['charge'] = ['charge_not_less_than_amount', 'An included charge must be less than the amount sent.'];
                }
            }
            if ($incentive) {
                if ($pct === null || $pct === '' || !FinancialNumber::isValid($pct, 2)) {
                    $errors['incentive_pct'] = ['incentive_invalid', 'Enter a percentage from 0 to 10 (maximum 2 decimals).'];
                } elseif (bccomp(Dec::of($pct), O::INCENTIVE_MAX, 2) > 0) {
                    $errors['incentive_pct'] = ['incentive_too_high', 'Incentive cannot be more than 10%.'];
                }
            }
        }

        $calc = null;
        if (!$errors) {
            $calc = ManagerCalculator::remittance($method, $amount, $rate, $charge, $chargeType, $incentive, $pct, $typed);
            if (bccomp($calc['remit_bdt'], '0', 2) <= 0) {
                $errors['amount'] = ['result_out_of_range', 'The calculated BDT must be more than 0.'];
            } elseif (bccomp($calc['remit_bdt'], O::BDT_MAX, 2) > 0) {
                $errors[$other ? 'remit_bdt' : 'amount'] = ['result_out_of_range', 'The calculated BDT exceeds the safety limit of 1,000,000,000 BDT.'];
            } elseif (!$other && isset($input['remit_bdt']) && $input['remit_bdt'] !== '' && bccomp(Dec::of($input['remit_bdt']), $calc['remit_bdt'], 2) !== 0) {
                $errors['remit_bdt'] = ['calc_mismatch', "The BDT should be {$calc['remit_bdt']}. Please update the app."];
            }
        }

        $when = $stored?->occurred_at;
        $zone = $stored?->timezone ?? $this->current->get()->timezone;
        if (array_key_exists('occurred_at', $input) || !$stored) {
            [$when, $zone, $e] = RecordTime::parse($input['occurred_at'] ?? null, $input['timezone'] ?? null, $this->current->get()->timezone);
            if ($e) {
                $errors[$e[0] === 'timezone_invalid' ? 'timezone' : 'occurred_at'] = $e;
            }
        }

        if ($errors) {
            Reject::fail($errors);
        }

        return [
            'method'        => $method,
            'currency'      => $currency,
            'amount'        => Dec::of($amount),
            'rate'          => Dec::of($rate),
            'charge'        => Dec::of($charge),
            'charge_type'   => $chargeType,
            'incentive'     => $incentive,
            'incentive_pct' => Dec::of($pct),
            'remit_bdt'     => $calc['remit_bdt'],
            'occurred_at'   => $when,
            'timezone'      => $zone,
            'notes'         => $pick('notes'),
        ];
    }
}
