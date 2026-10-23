<?php

namespace App\Services\Student;

use App\Exceptions\ApiException;
use App\Models\Business;
use App\Models\SenderRecipient;
use App\Models\StudentExpense;
use App\Models\StudentFee;
use App\Models\StudentMoneyIn;
use App\Models\StudentOpeningBalance;
use App\Models\StudentSetting;
use App\Support\Money\Dec;
use App\Support\Money\MoneyField;
use App\Support\Records\MoneyAudit;
use App\Support\Records\RecordId;
use App\Support\Records\RecordTime;
use App\Support\Sender\SenderOptions;
use App\Support\Tenancy\CurrentBusiness;
use App\Support\Time\BusinessClock;
use App\Support\Usage\EditPolicy;
use App\Support\Validation\Reject;
use App\Support\Validation\Text;
use Illuminate\Support\Facades\DB;

final class StudentService
{
    public const CATEGORIES = [
        'food', 'rent', 'transport', 'education', 'shopping',
        'health', 'bills', 'other',
    ];

    public const METHODS = ['Bank', 'bKash', 'Nagad', 'Rocket', 'Other'];

    public function __construct(private CurrentBusiness $current)
    {
    }

    public function enabled(): bool
    {
        return (bool) (StudentSetting::query()->first()?->enabled ?? false);
    }

    public function setEnabled(bool $enabled): bool
    {
        DB::transaction(function () use ($enabled): void {
            $business = $this->business();
            Business::query()->whereKey($business->id)->lockForUpdate()->firstOrFail();

            $setting = StudentSetting::query()->first();
            $old = $setting ? MoneyAudit::values($setting) : [];
            $setting ??= new StudentSetting();
            $setting->enabled = $enabled;

            if (!$setting->exists || $setting->isDirty()) {
                $setting->save();
                MoneyAudit::record($old ? 'updated' : 'created', $setting, $old, MoneyAudit::values($setting));
            }
        });

        return $this->enabled();
    }

    public function saveOpening(string $currency, mixed $amount): StudentOpeningBalance
    {
        $currency = $this->currency($currency);
        if ($error = MoneyField::check($amount, 'amount', 'the opening balance', '1000000000', positive: false)) {
            Reject::fail(['amount' => $error]);
        }

        return DB::transaction(function () use ($currency, $amount): StudentOpeningBalance {
            $business = $this->business();
            Business::query()->whereKey($business->id)->lockForUpdate()->firstOrFail();

            $balance = StudentOpeningBalance::query()->where('currency', $currency)->first();
            $old = $balance ? MoneyAudit::values($balance) : [];
            $balance ??= new StudentOpeningBalance(['uuid' => RecordId::make(null), 'currency' => $currency]);
            $balance->amount = Dec::of($amount);

            if (!$balance->exists || $balance->isDirty()) {
                $balance->save();
                MoneyAudit::record($old ? 'updated' : 'created', $balance, $old, MoneyAudit::values($balance));
            }

            return $balance->refresh();
        });
    }

    public function removeOpening(string $currency): void
    {
        $balance = StudentOpeningBalance::query()->where('currency', $this->currency($currency))->firstOrFail();
        DB::transaction(function () use ($balance): void {
            MoneyAudit::record('deleted', $balance, MoneyAudit::values($balance));
            $balance->delete();
        });
    }

    /** @return array{0: StudentMoneyIn, 1: bool} */
    public function saveMoneyIn(?StudentMoneyIn $receipt, array $input): array
    {
        if ($receipt !== null) {
            return $this->saveMoneyInUnserialized($receipt, $input);
        }

        return RecordId::withinCreationLock(
            StudentMoneyIn::class,
            $input['id'] ?? null,
            fn (): array => $this->saveMoneyInUnserialized(null, $input),
        );
    }

    private function saveMoneyInUnserialized(?StudentMoneyIn $receipt, array $input): array
    {
        if ($receipt === null && ($existing = RecordId::existing(StudentMoneyIn::class, $input['id'] ?? null))) {
            return [$existing, false];
        }

        $values = $this->moneyInValues($input, $receipt);
        return $this->persist($receipt ?? new StudentMoneyIn(['uuid' => RecordId::make($input['id'] ?? null)]), $values);
    }

    /** @return array{0: StudentExpense, 1: bool} */
    public function saveExpense(?StudentExpense $expense, array $input): array
    {
        if ($expense !== null) {
            return $this->saveExpenseUnserialized($expense, $input);
        }

        return RecordId::withinCreationLock(
            StudentExpense::class,
            $input['id'] ?? null,
            fn (): array => $this->saveExpenseUnserialized(null, $input),
        );
    }

    private function saveExpenseUnserialized(?StudentExpense $expense, array $input): array
    {
        if ($expense !== null && $expense->student_fee_id !== null) {
            throw new ApiException('STUDENT_FEE_LINKED', 'Manage this expense from its fee.', 409);
        }

        if ($expense === null && ($existing = RecordId::existing(StudentExpense::class, $input['id'] ?? null))) {
            return [$existing, false];
        }

        $values = $this->expenseValues($input, $expense);
        return $this->persist($expense ?? new StudentExpense(['uuid' => RecordId::make($input['id'] ?? null)]), $values);
    }

    /** @return array{0: StudentFee, 1: bool} */
    public function saveFee(?StudentFee $fee, array $input): array
    {
        if ($fee !== null) {
            return $this->saveFeeUnserialized($fee, $input);
        }

        return RecordId::withinCreationLock(
            StudentFee::class,
            $input['id'] ?? null,
            fn (): array => $this->saveFeeUnserialized(null, $input),
        );
    }

    private function saveFeeUnserialized(?StudentFee $fee, array $input): array
    {
        if ($fee === null && ($existing = RecordId::existing(StudentFee::class, $input['id'] ?? null))) {
            return [$existing, false];
        }

        $values = $this->feeValues($input, $fee);
        if ($fee !== null && $fee->paid_on !== null &&
            (bccomp($values['amount'], Dec::of($fee->amount), 2) !== 0 ||
                $values['currency'] !== $fee->currency)
        ) {
            throw new ApiException('STUDENT_FEE_PAID', 'Unmark the fee as paid before changing its amount or currency.', 409);
        }

        return $this->persist($fee ?? new StudentFee(['uuid' => RecordId::make($input['id'] ?? null)]), $values);
    }

    public function deleteMoneyIn(StudentMoneyIn $receipt): void
    {
        $this->softDelete($receipt);
    }

    public function deleteExpense(StudentExpense $expense): void
    {
        if ($expense->student_fee_id !== null) {
            throw new ApiException('STUDENT_FEE_LINKED', 'Manage this expense from its fee.', 409);
        }

        $this->softDelete($expense);
    }

    public function restoreMoneyIn(StudentMoneyIn $receipt): StudentMoneyIn
    {
        return $this->restoreSimple($receipt);
    }

    public function restoreExpense(StudentExpense $expense): StudentExpense
    {
        if ($expense->student_fee_id !== null) {
            throw new ApiException('STUDENT_FEE_LINKED', 'Manage this expense from its fee.', 409);
        }

        return $this->restoreSimple($expense);
    }

    public function markFeePaid(StudentFee $fee, ?string $paidOn = null): StudentFee
    {
        $paidOn ??= $this->today();
        $this->checkDate($paidOn, 'paid_on');

        return DB::transaction(function () use ($fee, $paidOn): StudentFee {
            $locked = StudentFee::query()->whereKey($fee->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->paid_on !== null) {
                return $locked;
            }

            $expense = StudentExpense::query()->withTrashed()
                ->where('student_fee_id', $locked->id)->lockForUpdate()->first();

            $fields = [
                'student_fee_id' => $locked->id,
                'category' => 'education',
                'amount' => Dec::of($locked->amount),
                'currency' => $locked->currency,
                'spent_on' => $paidOn,
                'note' => null,
            ];

            if ($expense === null) {
                $expense = StudentExpense::create([
                    ...$fields,
                    'uuid' => RecordId::make(null),
                ]);
                MoneyAudit::record('created', $expense, [], MoneyAudit::values($expense));
            } else {
                $previous = MoneyAudit::values($expense);
                $expense->fill($fields);
                if ($expense->trashed()) {
                    $expense->restore();
                    MoneyAudit::record('restored', $expense, $previous, MoneyAudit::values($expense));
                } else {
                    $expense->save();
                }
            }

            $old = MoneyAudit::values($locked);
            $locked->paid_on = $paidOn;
            $locked->save();
            MoneyAudit::record('updated', $locked, $old, MoneyAudit::values($locked));

            return $locked->refresh();
        });
    }

    public function unmarkFeePaid(StudentFee $fee): StudentFee
    {
        return DB::transaction(function () use ($fee): StudentFee {
            $locked = StudentFee::query()->whereKey($fee->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->paid_on === null) {
                return $locked;
            }

            $expense = StudentExpense::query()->where('student_fee_id', $locked->id)
                ->lockForUpdate()->first();

            if ($expense) {
                $this->softDelete($expense);
            }

            $old = MoneyAudit::values($locked);
            $locked->paid_on = null;
            $locked->save();
            MoneyAudit::record('updated', $locked, $old, MoneyAudit::values($locked));

            return $locked->refresh();
        });
    }

    public function deleteFee(StudentFee $fee): void
    {
        DB::transaction(function () use ($fee): void {
            $locked = StudentFee::query()->whereKey($fee->getKey())->lockForUpdate()->firstOrFail();
            $expense = StudentExpense::query()->where('student_fee_id', $locked->id)->first();
            if ($expense !== null) {
                $this->softDelete($expense);
            }
            $this->softDelete($locked);
        });
    }

    public function restoreFee(StudentFee $fee): StudentFee
    {
        return DB::transaction(function () use ($fee): StudentFee {
            $locked = StudentFee::query()->withTrashed()->whereKey($fee->getKey())
                ->lockForUpdate()->firstOrFail();

            if (!$locked->trashed()) {
                return $locked;
            }

            $locked->restore();
            MoneyAudit::record('restored', $locked);

            if ($locked->paid_on !== null) {
                $expense = StudentExpense::query()->withTrashed()
                    ->where('student_fee_id', $locked->id)->lockForUpdate()->firstOrFail();

                if ($expense->trashed()) {
                    $expense->restore();
                    MoneyAudit::record('restored', $expense);
                }
            }

            return $locked->refresh();
        });
    }

    /**
     * Exact-decimal totals separated by currency. Income from the Student
     * "Received" form is ALWAYS BDT; an optional exchange rate is illustrative.
     * The ending balance uses all actual receipts/expenses up to $to, whereas
     * period totals include only records between $from and $to.
     */
    public function summary(string $from, string $to): array
    {
        $currencies = [];

        $add = static function (array &$currencies, string $currency, string $field, mixed $amount): void {
            $currencies[$currency] ??= [
                'opening_balance' => '0.00',
                'period_received' => '0.00',
                'period_spent' => '0.00',
                'received_to_date' => '0.00',
                'spent_to_date' => '0.00',
            ];
            $currencies[$currency][$field] = bcadd(
                $currencies[$currency][$field],
                Dec::money($amount),
                2
            );
        };

        foreach (StudentOpeningBalance::query()->get() as $opening) {
            $add($currencies, $opening->currency, 'opening_balance', $opening->amount);
        }

        foreach (StudentMoneyIn::query()->where('received_on', '<=', $to)->get() as $receipt) {
            $add($currencies, 'BDT', 'received_to_date', $receipt->amount_bdt);
            if ($receipt->received_on->format('Y-m-d') >= $from) {
                $add($currencies, 'BDT', 'period_received', $receipt->amount_bdt);
            }
        }

        $byCategory = [];
        foreach (StudentExpense::query()->where('spent_on', '<=', $to)->get() as $expense) {
            $add($currencies, $expense->currency, 'spent_to_date', $expense->amount);
            if ($expense->spent_on->format('Y-m-d') >= $from) {
                $add($currencies, $expense->currency, 'period_spent', $expense->amount);
                $byCategory[$expense->currency][$expense->category] = bcadd(
                    $byCategory[$expense->currency][$expense->category] ?? '0.00',
                    Dec::money($expense->amount),
                    2
                );
            }
        }

        ksort($currencies);
        $rows = [];
        foreach ($currencies as $currency => $values) {
            $rows[] = [
                'currency' => $currency,
                ...$values,
                'remaining_as_of_to' => bcsub(
                    bcadd($values['opening_balance'], $values['received_to_date'], 2),
                    $values['spent_to_date'],
                    2
                ),
                'expense_categories' => $byCategory[$currency] ?? [],
            ];
        }

        return [
            'from' => $from,
            'to' => $to,
            'by_currency' => $rows,
        ];
    }

    private function moneyInValues(array $input, ?StudentMoneyIn $stored): array
    {
        $values = $this->pick($input, $stored, [
            'from_recipient_id', 'from_name', 'amount_bdt', 'rate_bdt_per_currency',
            'estimate_currency', 'method', 'received_on', 'note',
        ]);

        $amount = $values['amount_bdt'];
        $rate = $values['rate_bdt_per_currency'];
        $estimate = $values['estimate_currency'];
        $errors = [];

        if ($error = MoneyField::check($amount, 'amount_bdt', 'the received BDT', '1000000000')) {
            $errors['amount_bdt'] = $error;
        }
        if ($rate !== null && $rate !== '' &&
            ($error = MoneyField::check($rate, 'rate_bdt_per_currency', 'the estimated exchange rate', '1000000'))
        ) {
            $errors['rate_bdt_per_currency'] = $error;
        }

        if ($estimate !== null && !in_array($estimate, SenderOptions::CURRENCIES, true)) {
            $errors['estimate_currency'] = ['currency_invalid', 'Choose a supported foreign currency.'];
        }

        $method = $values['method'] ?? 'Bank';
        if (!in_array($method, self::METHODS, true)) {
            $errors['method'] = ['method_invalid', 'Choose a valid money-received method.'];
        }

        // New clients submit from_id (family UUID or __other__). Older clients
        // use from_recipient_id; keep that alias and preserve historic source tokens.
        if (array_key_exists('from_id', $input) && array_key_exists('from_recipient_id', $input)
            && $input['from_id'] !== $input['from_recipient_id']) {
            $errors['from_id'] = ['from_id_conflict', 'Choose one sender identifier.'];
        }

        $fromId = array_key_exists('from_id', $input)
            ? $input['from_id']
            : (array_key_exists('from_recipient_id', $input)
                ? $input['from_recipient_id']
                : ($stored?->from_source_id ?? $stored?->fromRecipient?->uuid));
        $fromName = $values['from_name'];
        $recipientKey = null;
        $sourceId = null;
        $legacyNames = [
            '__fam__' => 'পরিবার',
            '__father__' => 'বাবা',
            '__mother__' => 'মা',
            '__sib__' => 'ভাই-বোন',
        ];
        $relations = ['বাবা', 'মা', 'ভাই-বোন'];
        $relation = $input['from_relation'] ?? null;

        if ($relation !== null && (!is_string($relation) || !in_array($relation, $relations, true))) {
            $errors['from_relation'] = ['relation_invalid', 'Choose বাবা, মা or ভাই-বোন.'];
        }

        if ($fromId === null || $fromId === '' || $fromId === '__other__') {
            $sourceId = '__other__';
        } elseif (is_string($fromId) && array_key_exists($fromId, $legacyNames)) {
            $sourceId = $fromId;
            if ($fromName === null || $fromName === '') {
                $fromName = $legacyNames[$fromId];
            }
        } else {
            $recipient = is_string($fromId)
                ? SenderRecipient::query()->where('uuid', $fromId)->first()
                : null;
            // An archived family member may remain linked on an existing record,
            // but cannot be newly selected from the student form.
            $unchanged = $stored !== null && $recipient !== null
                && (int) $stored->from_recipient_id === (int) $recipient->id;
            if ($recipient === null || ($recipient->archived && !$unchanged)) {
                $errors['from_id'] = ['recipient_not_found', 'Choose an active family member.'];
            } else {
                $recipientKey = $recipient->id;
                $fromName = $recipient->name;
            }
            if ($relation !== null) {
                $errors['from_relation'] = ['relation_not_allowed', 'A relation is only used for Other.'];
            }
        }

        if ($sourceId !== null && $relation !== null && !isset($errors['from_relation'])) {
            // Do not double-append a relation when editing an existing receipt.
            $base = is_string($fromName)
                ? trim((string) preg_replace('/\s+\((?:বাবা|মা|ভাই-বোন)\)$/u', '', $fromName))
                : '';
            if (in_array($base, $relations, true)) {
                $base = '';
            }
            $fromName = $base === '' ? $relation : $base . ' (' . $relation . ')';
        }

        if (!$this->validPlainName($fromName, 60)) {
            $errors['from_name'] = ['from_name_invalid', 'Enter a sender name or relation (maximum 60 characters).'];
        }

        $date = $values['received_on'] ?? $this->today();
        if ($error = RecordTime::date($date, $this->timezone())) {
            $errors['received_on'] = $error;
        }

        $this->checkNote($values['note'], $errors);
        if ($errors) {
            Reject::fail($errors);
        }

        return [
            'from_recipient_id' => $recipientKey,
            'from_source_id' => $sourceId,
            'from_name' => trim($fromName),
            'amount_bdt' => Dec::of($amount),
            'rate_bdt_per_currency' => $rate === null || $rate === '' ? null : Dec::of($rate),
            'estimate_currency' => $estimate,
            'method' => $method,
            'received_on' => $date,
            'note' => $values['note'],
        ];
    }

    private function expenseValues(array $input, ?StudentExpense $stored): array
    {
        $v = $this->pick($input, $stored, ['category', 'amount', 'currency', 'spent_on', 'note']);
        $errors = [];

        if (!in_array($v['category'], self::CATEGORIES, true)) {
            $errors['category'] = ['category_invalid', 'Choose an expense category.'];
        }
        if ($error = MoneyField::check($v['amount'], 'amount', 'the expense amount', '1000000000')) {
            $errors['amount'] = $error;
        }

        $currency = $v['currency'] ?? $this->business()->default_currency ?? 'FJD';
        $currency = $this->validateCurrency($currency, $errors);

        $date = $v['spent_on'] ?? $this->today();
        if ($error = RecordTime::date($date, $this->timezone())) {
            $errors['spent_on'] = $error;
        }

        $this->checkNote($v['note'], $errors);
        if ($errors) {
            Reject::fail($errors);
        }

        return [
            'category' => $v['category'],
            'amount' => Dec::of($v['amount']),
            'currency' => $currency,
            'spent_on' => $date,
            'note' => $v['note'],
        ];
    }

    private function feeValues(array $input, ?StudentFee $stored): array
    {
        $v = $this->pick($input, $stored, ['name', 'amount', 'currency', 'due_on']);
        $errors = [];
        if (!$this->validPlainName($v['name'], 40)) {
            $errors['name'] = ['name_invalid', 'Enter a fee name (maximum 40 characters).'];
        }
        if ($error = MoneyField::check($v['amount'], 'amount', 'the fee amount', '1000000000')) {
            $errors['amount'] = $error;
        }
        $currency = $this->validateCurrency($v['currency'] ?? $this->business()->default_currency ?? 'FJD', $errors);
        $date = $v['due_on'];
        if ($error = RecordTime::date($date, $this->timezone(), allowFuture: true)) {
            $errors['due_on'] = $error;
        }
        if ($errors) {
            Reject::fail($errors);
        }

        return [
            'name' => trim($v['name']),
            'amount' => Dec::of($v['amount']),
            'currency' => $currency,
            'due_on' => $date,
        ];
    }

    /** @return array{0: \Illuminate\Database\Eloquent\Model, 1: bool} */
    private function persist(
        StudentMoneyIn|StudentExpense|StudentFee $record,
        array $values
    ): array {
        return DB::transaction(function () use ($record, $values): array {
            $created = !$record->exists;
            $old = $created ? [] : MoneyAudit::values($record);
            $record->fill($values);
            if ($created || $record->isDirty()) {
                $record->save();
                if ($created || EditPolicy::hasRealChange($old, MoneyAudit::values($record))) {
                    MoneyAudit::record($created ? 'created' : 'updated', $record, $old, MoneyAudit::values($record));
                }
            }

            return [$record->refresh(), $created];
        });
    }

    private function softDelete(StudentMoneyIn|StudentExpense|StudentFee $record): void
    {
        $old = MoneyAudit::values($record);
        $record->delete();
        MoneyAudit::record('deleted', $record, $old);
    }

    private function restoreSimple(StudentMoneyIn|StudentExpense $record): StudentMoneyIn|StudentExpense
    {
        if ($record->trashed()) {
            DB::transaction(function () use ($record): void {
                $record->restore();
                MoneyAudit::record('restored', $record);
            });
        }

        return $record->refresh();
    }

    private function pick(array $input, ?object $record, array $fields): array
    {
        $result = [];
        foreach ($fields as $field) {
            $value = $record?->{$field};
            if ($value instanceof \DateTimeInterface) {
                $value = $value->format('Y-m-d');
            }
            $result[$field] = array_key_exists($field, $input) ? $input[$field] : $value;
        }

        return $result;
    }

    private function validPlainName(mixed $value, int $max): bool
    {
        return is_string($value) && trim($value) !== ''
            && mb_strlen(trim($value)) <= $max
            && !Text::containsCode($value);
    }

    private function checkNote(mixed $note, array &$errors): void
    {
        if ($note !== null && (!is_string($note) || mb_strlen($note) > 100 || Text::containsCode($note))) {
            $errors['note'] = ['note_invalid', 'Use plain text, maximum 100 characters.'];
        }
    }

    private function currency(string $currency): string
    {
        $errors = [];
        $result = $this->validateCurrency($currency, $errors);
        if ($errors) {
            Reject::fail($errors);
        }

        return $result;
    }

    private function validateCurrency(mixed $currency, array &$errors): string
    {
        if (!is_string($currency) || !in_array($currency, ['BDT', ...SenderOptions::CURRENCIES], true)) {
            $errors['currency'] = ['currency_invalid', 'Choose a supported currency.'];
        }

        return is_string($currency) ? $currency : '';
    }

    private function checkDate(mixed $date, string $field): void
    {
        if ($error = RecordTime::date($date, $this->timezone())) {
            Reject::fail([$field => $error]);
        }
    }

    private function today(): string
    {
        return BusinessClock::for($this->business())->toLocal(now())->format('Y-m-d');
    }

    private function timezone(): string
    {
        return $this->business()->timezone;
    }

    private function business(): Business
    {
        return $this->current->get()
            ?? throw new \LogicException('Student operations require an active business.');
    }
}
