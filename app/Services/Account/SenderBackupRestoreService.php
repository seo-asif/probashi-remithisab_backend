<?php

namespace App\Services\Account;

use App\Models\Business;
use App\Models\SenderRecipient;
use App\Models\SenderRemittance;
use App\Models\SavingsGoal;
use App\Support\Money\CurrencyRules;
use App\Support\Money\Dec;
use App\Support\Money\MoneyField;
use App\Support\Plans\PlanQuotaService;
use App\Support\Records\MoneyAudit;
use App\Support\Records\RecordId;
use App\Support\Sender\SenderCalculator;
use App\Support\Sender\SenderOptions as O;
use App\Support\Sender\SenderValidation;
use App\Support\Tenancy\CurrentBusiness;
use App\Support\Validation\Text;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Merge-only restore for a verified Sender backup. Existing owner records are
 * never overwritten or undeleted. Every insert and quota update is atomic.
 * Backup-supplied owner ids, numeric ids and quota counters are NEVER trusted.
 */
final class SenderBackupRestoreService
{
    public function __construct(
        private CurrentBusiness $current,
        private PlanQuotaService $quota,
    ) {}

    public function restore(array $backup): array
    {
        $business = $this->current->get();
        $userId = $this->current->userId();
        if ($business === null || $userId === null) {
            throw new \LogicException('Sender restore requires an authenticated business and owner.');
        }

        return DB::transaction(function () use ($backup, $business, $userId): array {
            // Serialize imports with ordinary record creation and quota changes.
            Business::query()->whereKey($business->id)->lockForUpdate()->firstOrFail();
            $this->validateRows($backup);

            $counts = [];
            foreach (['recipients', 'goals', 'remittances'] as $dataset) {
                $counts[$dataset] = ['created' => 0, 'skipped_existing' => 0];
            }

            $recipientIds = [];
            foreach ($backup['datasets']['recipients'] as $row) {
                $existing = RecordId::existing(SenderRecipient::class, $row['uuid']);
                if ($existing !== null) {
                    $counts['recipients']['skipped_existing']++;
                    $recipientIds[strtolower($row['uuid'])] = $existing->id;
                    continue;
                }
                $this->quota->assertFamilyMemberAvailable($business, $userId);
                $phone = SenderValidation::cleanPhone($row['phone'] ?? null);
                $key = SenderValidation::phoneKey($phone);
                if ($key !== '' && empty($row['deleted_at'])) {
                    $same = SenderRecipient::query()->where('phone_normalized', $key)->exists();
                    if ($same) {
                        throw ValidationException::withMessages([
                            'datasets.recipients' => ['An active family contact number is already used.'],
                        ]);
                    }
                }

                $recipient = new SenderRecipient();
                $recipient->forceFill([
                    'uuid' => strtolower($row['uuid']),
                    'name' => trim($row['name']),
                    'relationship' => $row['relationship'] ?? null,
                    'phone' => $phone,
                    'phone_normalized' => $key !== '' ? $key : null,
                    'notes' => $row['notes'] ?? null,
                    'archived' => (bool) ($row['archived'] ?? false),
                    'archived_at' => $row['archived_at'] ?? null,
                    'deleted_at' => $row['deleted_at'] ?? null,
                ])->save();
                $recipientIds[strtolower($row['uuid'])] = $recipient->id;
                $counts['recipients']['created']++;
            }

            $goalIds = [];
            foreach ($backup['datasets']['goals'] as $row) {
                $existing = RecordId::existing(SavingsGoal::class, $row['uuid']);
                if ($existing !== null) {
                    $counts['goals']['skipped_existing']++;
                    $goalIds[strtolower($row['uuid'])] = $existing->id;
                    continue;
                }
                $goal = new SavingsGoal();
                $goal->forceFill([
                    'uuid' => strtolower($row['uuid']),
                    'name' => trim($row['name']),
                    'target_bdt' => Dec::of($row['target_bdt']),
                    'target_date' => $row['target_date'] ?? null,
                    'deleted_at' => $row['deleted_at'] ?? null,
                ])->save();
                $goalIds[strtolower($row['uuid'])] = $goal->id;
                $counts['goals']['created']++;
            }

            foreach ($backup['datasets']['remittances'] as $row) {
                if (RecordId::existing(SenderRemittance::class, $row['uuid']) !== null) {
                    $counts['remittances']['skipped_existing']++;
                    continue;
                }

                $money = $this->calculated($row);
                $recipientUuid = $row['recipient_uuid'] ?? null;
                $goalUuid = $row['goal_uuid'] ?? null;
                $recipientId = $recipientUuid === null ? null : $recipientIds[strtolower($recipientUuid)];
                $goalId = $goalUuid === null ? null : $goalIds[strtolower($goalUuid)];

                // Imported records are NEW creations for quota purposes.
                // Keep occurred_at (financial event date), but use today's
                // created_at so refunds and monthly usage remain consistent.
                $this->quota->consumeSenderRemittance($business, $userId);

                $remittance = new SenderRemittance();
                $remittance->forceFill([
                    'uuid' => strtolower($row['uuid']),
                    'recipient_type' => $row['recipient_type'],
                    'recipient_id' => $recipientId,
                    'recipient_name' => $row['recipient_name'],
                    'currency' => $row['currency'],
                    'amount' => Dec::of($row['amount']),
                    'rate' => Dec::of($row['rate'], CurrencyRules::rateDecimals($row['currency'])),
                    'charge' => Dec::of($row['charge']),
                    'charge_type' => $row['charge_type'],
                    'incentive_pct' => Dec::of($row['incentive_pct']),
                    'method' => $row['method'],
                    'principal' => $money['principal'],
                    'incentive_bdt' => $money['incentive_bdt'],
                    'family_bdt' => $money['family_bdt'],
                    'total_cost' => $money['total_cost'],
                    'received' => (bool) $row['received'],
                    'received_at' => $row['received_at'] ?? null,
                    'purpose' => $row['purpose'] ?? null,
                    'notes' => $row['notes'] ?? null,
                    'occurred_at' => $row['occurred_at'],
                    'timezone' => $row['timezone'],
                    'deleted_at' => $row['deleted_at'] ?? null,
                ])->save();

                MoneyAudit::record('created', $remittance, [], MoneyAudit::values($remittance));
                $counts['remittances']['created']++;
            }

            return [
                'datasets' => $counts,
                'created_total' => array_sum(array_column($counts, 'created')),
                'skipped_total' => array_sum(array_column($counts, 'skipped_existing')),
                'mode' => 'merge_only',
                'existing_records_unchanged' => true,
                'timestamps' => 'New records use restore-time created_at and updated_at; original occurred_at and soft-delete state are retained.',
            ];
        }, 3);
    }

    /** Validate the entire file before the first insert. */
    private function validateRows(array $backup): void
    {
        foreach ($backup['datasets']['recipients'] as $i => $row) {
            Validator::make($row, [
                'uuid' => ['required', 'uuid'],
                'name' => ['required', 'string', 'max:60', new \App\Rules\PersonName()],
                'relationship' => ['nullable', Rule::in(O::RELATIONSHIPS)],
                'phone' => ['nullable', 'string', 'max:24'],
                'notes' => ['nullable', 'string', 'max:100', new \App\Rules\PlainText()],
                'archived' => ['sometimes', 'boolean'],
                'archived_at' => ['nullable', 'date'],
                'deleted_at' => ['nullable', 'date'],
            ])->validate();
            $phone = SenderValidation::cleanPhone($row['phone'] ?? null);
            if ($phone !== null && !preg_match(O::PHONE_REGEX, $phone)) {
                $this->invalid("datasets.recipients.$i.phone", 'Invalid family contact number.');
            }
        }

        foreach ($backup['datasets']['goals'] as $i => $row) {
            Validator::make($row, [
                'uuid' => ['required', 'uuid'],
                'name' => ['required', 'string', 'max:30'],
                'target_bdt' => ['required'],
                'target_date' => ['nullable', 'date_format:Y-m-d'],
                'deleted_at' => ['nullable', 'date'],
            ])->validate();
            if (Text::containsCode($row['name'])) {
                $this->invalid("datasets.goals.$i.name", 'Invalid goal name.');
            }
            if (MoneyField::check($row['target_bdt'], 'target_bdt', 'target BDT', O::GOAL_TARGET_MAX)) {
                $this->invalid("datasets.goals.$i.target_bdt", 'Invalid goal target.');
            }
        }

        foreach ($backup['datasets']['remittances'] as $i => $row) {
            Validator::make($row, [
                'uuid' => ['required', 'uuid'],
                'recipient_type' => ['required', Rule::in(O::RECIPIENT_TYPES)],
                'recipient_uuid' => ['nullable', 'uuid'],
                'recipient_name' => ['required', 'string', 'max:60'],
                'goal_uuid' => ['nullable', 'uuid'],
                'currency' => ['required', Rule::in(O::CURRENCIES)],
                'amount' => ['required'],
                'rate' => ['required'],
                'charge' => ['required'],
                'charge_type' => ['required', Rule::in(O::CHARGE_TYPES)],
                'incentive_pct' => ['required'],
                'method' => ['required', Rule::in(O::METHODS)],
                'principal' => ['required'],
                'incentive_bdt' => ['required'],
                'family_bdt' => ['required'],
                'total_cost' => ['required'],
                'received' => ['required', 'boolean'],
                'received_at' => ['nullable', 'date'],
                'purpose' => ['nullable', Rule::in(O::PURPOSES)],
                'notes' => ['nullable', 'string', 'max:100', new \App\Rules\PlainText()],
                'occurred_at' => ['required', 'date_format:Y-m-d H:i:s'],
                'timezone' => ['required', 'timezone'],
                'deleted_at' => ['nullable', 'date'],
            ])->validate();

            $isFamily = $row['recipient_type'] === O::RECIPIENT_FAMILY;
            if ($isFamily !== !empty($row['recipient_uuid'])) {
                $this->invalid("datasets.remittances.$i.recipient_uuid", 'Recipient reference does not match transfer type.');
            }

            $this->calculated($row, $i);
        }
    }

    private function calculated(array $row, ?int $index = null): array
    {
        $currency = $row['currency'];
        $money = [
            'amount' => $row['amount'],
            'rate' => CurrencyRules::storedRate($row['rate'], $currency),
            'charge' => $row['charge'],
            'charge_type' => $row['charge_type'],
            'incentive_pct' => $row['incentive_pct'],
        ];
        if (SenderValidation::moneyErrors($money, $currency) !== []) {
            $this->invalid('datasets.remittances.' . ($index ?? 0), 'Invalid remittance financial input.');
        }
        $calculated = SenderCalculator::breakdown(
            $money['amount'],
            $money['rate'],
            $money['charge'],
            $money['charge_type'],
            $money['incentive_pct']
        );
        foreach (['principal', 'incentive_bdt', 'family_bdt', 'total_cost'] as $field) {
            if (!is_string($row[$field]) && !is_int($row[$field])) {
                $this->invalid("datasets.remittances." . ($index ?? 0) . ".$field", 'Invalid calculated amount.');
            }
            if (!preg_match('/^\d{1,16}(?:\.\d{1,2})?$/D', (string) $row[$field])
                || bccomp((string) $row[$field], $calculated[$field], 2) !== 0) {
                $this->invalid("datasets.remittances." . ($index ?? 0) . ".$field", 'Backup totals do not match server calculations.');
            }
        }
        return $calculated;
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => [$message]]);
    }
}
