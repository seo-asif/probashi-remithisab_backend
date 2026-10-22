<?php

namespace App\Services\Sender;

use App\Exceptions\ApiException;
use App\Models\SavingsGoal;
use App\Models\SenderRecipient;
use App\Models\SenderRemittance;
use App\Support\Money\Dec;
use App\Support\Money\MoneyField;
use App\Support\Plans\PlanQuotaService;
use App\Support\Records\MoneyAudit;
use App\Support\Records\RecordId;
use App\Support\Records\RecordTime;
use App\Support\Sender\SenderCalculator;
use App\Support\Sender\SenderOptions as O;
use App\Support\Sender\SenderValidation;
use App\Support\Tenancy\CurrentBusiness;
use App\Support\Usage\EditPolicy;
use App\Support\Validation\Reject;
use Illuminate\Support\Facades\DB;

/**
 * Sender mode saves. Every query runs under BelongsToOwner: a user only reaches their OWN recipients, remittances
 * and goals (business owners and administrators included). Phase 4 adds the per-user plan limits here.
 */
final class SenderService
{
    public function __construct(
        private CurrentBusiness $current,
        private PlanQuotaService $quota,
    ) {
    }

    // ------------------------------------------------------------------ recipients

    /** @return array{0: SenderRecipient, 1: bool} */
    public function createRecipient(array $data): array
    {
        if ($existing = RecordId::existing(SenderRecipient::class, $data['id'] ?? null)) {
            return [$existing, false];
        }

  $r = DB::transaction(function () use ($data) {
    $business = $this->current->get();
    $userId = $this->current->userId();

    if ($business === null || $userId === null) {
        throw new \LogicException(
            'Family member creation requires a current business and user.'
        );
    }

    $this->quota->assertFamilyMemberAvailable(
        $business,
        $userId,
    );

    return SenderRecipient::create([
        ...$this->recipientAttributes($data),
        'uuid' => RecordId::make($data['id'] ?? null),
    ]);
});

        return [$r->refresh(), true];
    }

    public function updateRecipient(SenderRecipient $r, array $data): SenderRecipient
    {
        $r->fill($this->recipientAttributes($data, partial: true));
        $r->isDirty() && $r->save();

        return $r->refresh();
    }

    public function restoreRecipient(SenderRecipient $r): SenderRecipient
    {
        if (!$r->trashed()) {
            return $r;
        }

        if ($r->phone_normalized) {
            $other = SenderRecipient::query()->where('phone_normalized', $r->phone_normalized)->whereKeyNot($r->id)->first();
            if ($other) {
                throw new ApiException('DUPLICATE_RECIPIENT', 'A family member already uses this contact number: ' . $other->name, 409, [
                    'recipient' => ['id' => $other->uuid, 'name' => $other->name, 'phone' => $other->phone],
                ]);
            }
        }

        DB::transaction(function () use ($r) {
            // Restore reuses the existing identity and its original quota slot.
            // It remains allowed after a paid plan expires.
            $r->restore();
        });

        return $r->refresh();
    }

    private function recipientAttributes(array $data, bool $partial = false): array
    {
        $out = [];
        foreach (['name', 'relationship', 'notes'] as $f) {
            if (!$partial || array_key_exists($f, $data)) {
                $out[$f] = $data[$f] ?? null;
            }
        }
        if (!$partial || array_key_exists('phone', $data)) {
            $phone = $data['phone'] ?? null;
            $key = is_string($phone) ? SenderValidation::phoneKey($phone) : '';
            $out['phone'] = $phone ?: null;
            $out['phone_normalized'] = $key !== '' ? $key : null;
        }

        return $out;
    }

    // ------------------------------------------------------------------ remittances

    /** @return array{0: SenderRemittance, 1: bool} */
    public function createRemittance(array $input): array
    {
        if ($existing = RecordId::existing(SenderRemittance::class, $input['id'] ?? null)) {
            return [$existing, false];
        }

        $values = $this->remittanceValues($input, null);

        $r = DB::transaction(function () use ($input, $values) {
            $business = $this->current->get();
            $userId = $this->current->userId();

            if ($business === null || $userId === null) {
                throw new \LogicException(
                    'Sender remittance creation requires a current business and user.'
                );
            }

            $this->quota->consumeSenderRemittance(
                $business,
                $userId,
            );

            $r = SenderRemittance::create([
                 ...$values,
                'uuid'     => RecordId::make($input['id'] ?? null),
                'received' => false,
            ]);

            MoneyAudit::record(
                'created',
                $r,
                [],
                MoneyAudit::values($r)
            );

            return $r;
        });

        return [$r->refresh(), true];
    }

   public function updateRemittance(
    SenderRemittance $r,
    array $input,
): SenderRemittance {
    $values = $this->remittanceValues($input, $r);

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

    /** "ওরা পেয়েছে ✓": the family confirmed. Sending false undoes it. */
    public function markReceived(SenderRemittance $r, bool $received): SenderRemittance
    {
        if ((bool) $r->received === $received) {
            return $r;
        }

        return DB::transaction(function () use ($r, $received) {
            $r->forceFill(['received' => $received, 'received_at' => $received ? now() : null])->save();
            MoneyAudit::record($received ? 'received' : 'not_received', $r);

            return $r->refresh();
        });
    }

    public function deleteRemittance(SenderRemittance $r): void
    {
        DB::transaction(function () use ($r) {
            $r->delete();
            MoneyAudit::record('deleted', $r);
        });
    }

    public function restoreRemittance(SenderRemittance $r): SenderRemittance
    {
        if ($r->trashed()) {
            DB::transaction(function () use ($r) {
                $r->restore();
                MoneyAudit::record('restored', $r);
            });
        }

        return $r->refresh();
    }

    /** Checks the FINAL record (stored values merged with the changes) and computes every total. */
    private function remittanceValues(array $input, ?SenderRemittance $stored): array
    {
        // a recipient given on its own means "send to this family member"
        if (!empty($input['recipient_id']) && !array_key_exists('recipient_type', $input)) {
            $input['recipient_type'] = O::RECIPIENT_FAMILY;
        }

        $pick = fn(string $k, mixed $d = null) => array_key_exists($k, $input) ? $input[$k] : ($stored?->{ $k} ?? $d);
        $errors = [];

        $type = $pick('recipient_type');
        if (!in_array($type, O::RECIPIENT_TYPES, true)) {
            $errors['recipient_type'] = ['recipient_required', 'Choose a family member or Myself.'];
        }

        $recipient = null;
        if ($type === O::RECIPIENT_FAMILY) {
            if (array_key_exists('recipient_id', $input) || $stored?->recipient_type !== O::RECIPIENT_FAMILY) {
                $recipient = is_string($input['recipient_id'] ?? null) ? SenderRecipient::query()->where('uuid', $input['recipient_id'])->first() : null;
                if (!$recipient) {
                    $errors['recipient_id'] = ['recipient_required', 'Select a saved family member or recipient.'];
                }
            } else {
                $recipient = $stored->recipient; // unchanged (still readable if deleted later)
            }
        } elseif ($type === O::RECIPIENT_SELF && !empty($input['recipient_id'])) {
            $errors['recipient_id'] = ['recipient_not_allowed', 'Myself needs no family member.'];
        }

        $currency = $pick('currency');
        if (!in_array($currency, O::CURRENCIES, true)) {
            $errors['currency'] = ['currency_invalid', 'Choose a currency from the list.'];
        }
        $method = $pick('method');
        if (!in_array($method, O::METHODS, true)) {
            $errors['method'] = ['method_invalid', 'Choose how it was sent: bKash, Nagad, Rocket, Bank or Other.'];
        }
        $purpose = $pick('purpose');
        if ($purpose !== null && !in_array($purpose, O::PURPOSES, true)) {
            $errors['purpose'] = ['purpose_invalid', 'Choose what the money was for from the list.'];
        }

        $goal = $stored?->goal_id;
        if (array_key_exists('goal_id', $input)) {
            $goal = null;
            if ($input['goal_id'] !== null) {
                $found = is_string($input['goal_id']) ? SavingsGoal::query()->where('uuid', $input['goal_id'])->first() : null;
                if ($found) {
                    $goal = $found->id;
                } else {
                    $errors['goal_id'] = ['goal_not_found', 'Choose one of your savings goals.'];
                }
            }
        }

        $money = ['amount' => $pick('amount'), 'rate' => $pick('rate'), 'charge' => $pick('charge'), 'charge_type' => $pick('charge_type'), 'incentive_pct' => $pick('incentive_pct', '0')];
        $errors += SenderValidation::moneyErrors($money);

        $calc = null;
        if (!array_intersect_key($errors, array_flip(['amount', 'rate', 'charge', 'charge_type', 'incentive_pct']))) {
            $calc = SenderCalculator::breakdown($money['amount'], $money['rate'], $money['charge'], $money['charge_type'], $money['incentive_pct']);
            if (isset($input['family_bdt']) && $input['family_bdt'] !== '' && bccomp(Dec::of($input['family_bdt']), $calc['family_bdt'], 2) !== 0) {
                $errors['family_bdt'] = ['calc_mismatch', "The family amount should be {$calc['family_bdt']} BDT. Please update the app."];
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
            'recipient_type' => $type,
            'recipient_id'   => $recipient?->id,
            'recipient_name' => $recipient ? $recipient->name : SenderRemittance::MYSELF_NAME,
            'currency'       => $currency,
            'amount'         => Dec::of($money['amount']),
            'rate'           => Dec::of($money['rate']),
            'charge'         => Dec::of($money['charge']),
            'charge_type'    => $money['charge_type'],
            'incentive_pct'  => Dec::of($money['incentive_pct']),
            'method'         => $method,
            'principal'      => $calc['principal'],
            'incentive_bdt'  => $calc['incentive_bdt'],
            'family_bdt'     => $calc['family_bdt'],
            'total_cost'     => $calc['total_cost'],
            'purpose'        => $purpose,
            'goal_id'        => $goal,
            'notes'          => $pick('notes'),
            'occurred_at'    => $when,
            'timezone'       => $zone,
        ];
    }

    // ------------------------------------------------------------------ savings goals

    /** @return array{0: SavingsGoal, 1: bool} */
    public function saveGoal(?SavingsGoal $goal, array $input): array
    {
        if (!$goal && ($existing = RecordId::existing(SavingsGoal::class, $input['id'] ?? null))) {
            return [$existing, false];
        }

        $name = array_key_exists('name', $input) ? (is_string($input['name']) ? trim($input['name']) : null) : $goal?->name;
        $target = array_key_exists('target_bdt', $input) ? $input['target_bdt'] : $goal?->target_bdt;
        $date = array_key_exists('target_date', $input) ? ($input['target_date'] ?: null): $goal?->target_date?->format('Y-m-d');
        $errors = [];

        if ($name === null || $name === '') {
            $errors['name'] = ['goal_name_required', 'Write a short name.'];
        } elseif (mb_strlen($name) > O::GOAL_NAME_MAX || \App\Support\Validation\Text::containsCode($name)) {
            $errors['name'] = ['goal_name_invalid', 'Use plain text, at most 30 characters.'];
        }
        if ($e = MoneyField::check($target, 'target_bdt', 'the taka you need', O::GOAL_TARGET_MAX)) {
            $errors['target_bdt'] = $e;
        }
        if ($date !== null && ($e = RecordTime::date($date, $this->current->get()->timezone, allowFuture: true))) {
            $errors['target_date'] = $e;
        }
        if ($errors) {
            Reject::fail($errors);
        }

        $values = ['name' => $name, 'target_bdt' => Dec::of($target), 'target_date' => $date];
        $goal ??= new SavingsGoal(['uuid' => RecordId::make($input['id'] ?? null)]);
        $created = !$goal->exists;
        $goal->fill($values)->save();

        // optional: count money already sent to myself that is not in a goal yet
        if ($created && !empty($input['include_past_self'])) {
            SenderRemittance::query()->where('recipient_type', O::RECIPIENT_SELF)->whereNull('goal_id')->update(['goal_id' => $goal->id]);
        }

        return [$goal->refresh(), $created];
    }

    public function deleteGoal(SavingsGoal $goal): void
    {
        DB::transaction(function () use ($goal) {
            SenderRemittance::withTrashed()->where('goal_id', $goal->id)->update(['goal_id' => null]); // the app's rule
            $goal->delete();
        });
    }

    /** BDT saved toward a goal = family_bdt of its active remittances. */
    public function goalProgress(SavingsGoal $goal): array
    {
        $saved = Dec::of((string) SenderRemittance::query()->where('goal_id', $goal->id)->sum('family_bdt'));
        $left = bcsub(Dec::of($goal->target_bdt), $saved, 2);

        return ['saved_bdt' => $saved, 'left_bdt' => bccomp($left, '0', 2) > 0 ? $left : '0.00', 'reached' => bccomp($saved, Dec::of($goal->target_bdt), 2) >= 0];
    }
}
