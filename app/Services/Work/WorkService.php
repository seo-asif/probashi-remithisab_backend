<?php

namespace App\Services\Work;

use App\Models\LoanEntry;
use App\Models\Salary;
use App\Models\SpendingEntry;
use App\Models\WorkBonus;
use App\Models\WorkDay;
use App\Models\WorkSetting;
use App\Support\Money\Dec;
use App\Support\Money\FinancialNumber;
use App\Support\Money\MoneyField;
use App\Support\Records\MoneyAudit;
use App\Support\Records\RecordId;
use App\Support\Records\RecordTime;
use App\Support\Sender\SenderOptions;
use App\Support\Tenancy\CurrentBusiness;
use App\Support\Validation\Reject;
use App\Support\Validation\Text;
use App\Support\Work\WorkCalculator as W;
use Illuminate\Support\Facades\DB;

/** Worker tools (Money Rules 4). Every query runs under BelongsToOwner: private to each user. */
final class WorkService
{
    public function __construct(private CurrentBusiness $current)
    {
    }

    // ------------------------------------------------------------------ settings

    public function settings(): ?WorkSetting
    {
        return WorkSetting::query()->first();
    }

    public function saveSettings(array $input): WorkSetting
    {
        $s = $this->settings() ?? new WorkSetting(['day_hours' => '8.00', 'ot_multiplier' => '1.50']);
        $errors = [];

        $rate = array_key_exists('hourly_rate', $input) ? $input['hourly_rate'] : $s->hourly_rate;
        if ($rate !== null && $rate !== '' && ($e = MoneyField::check($rate, 'hourly_rate', 'the pay for 1 hour', '1000'))) {
            $errors['hourly_rate'] = $e;
        }
        $hours = array_key_exists('day_hours', $input) ? $input['day_hours'] : $s->day_hours;
        if (!FinancialNumber::isValid($hours, 2) || bccomp(Dec::of($hours), '1', 2) < 0 || bccomp(Dec::of($hours), '24', 2) > 0) {
            $errors['day_hours'] = ['day_hours_invalid', 'Normal hours in a day must be from 1 to 24.'];
        }
        $ot = array_key_exists('ot_multiplier', $input) ? $input['ot_multiplier'] : $s->ot_multiplier;
        if (!FinancialNumber::isValid($ot, 2) || bccomp(Dec::of($ot), '1', 2) < 0 || bccomp(Dec::of($ot), '5', 2) > 0) {
            $errors['ot_multiplier'] = ['ot_multiplier_invalid', 'Overtime pay must be from 1 to 5 times.'];
        }
        $currency = array_key_exists('currency', $input) ? (is_string($input['currency']) ? strtoupper(trim($input['currency'])) : null) : $s->currency;
        if ($currency !== null && !in_array($currency, SenderOptions::CURRENCIES, true)) {
            $errors['currency'] = ['currency_invalid', 'Choose a currency from the list.'];
        }
        if ($errors) {
            Reject::fail($errors);
        }

        $old = $s->exists ? MoneyAudit::values($s) : [];
        $s->fill(['hourly_rate' => ($rate === null || $rate === '') ? null : Dec::of($rate), 'day_hours' => Dec::of($hours), 'ot_multiplier' => Dec::of($ot), 'currency' => $currency])->save();
        MoneyAudit::record($old ? 'updated' : 'created', $s, $old, MoneyAudit::values($s));

        return $s->refresh();
    }

    public function currency(): string
    {
        return $this->settings()?->currency ?? $this->current->get()->default_currency ?? 'FJD';
    }

    // ------------------------------------------------------------------ days and bonuses

    /** One entry per day: saving a date again replaces it. */
    public function saveDay(string $date, array $input): WorkDay
    {
        $errors = [];
        if ($e = RecordTime::date($date, $this->tz())) {
            $errors['work_date'] = $e;
        }
        $kind = $input['kind'] ?? null;
        if (!in_array($kind, W::KINDS, true)) {
            $errors['kind'] = ['kind_invalid', 'Choose work, holiday, sick or day off.'];
        }
        $hours  = '0.00';
        $amount = null;
        if ($kind === 'work') {
            $h = $input['hours'] ?? null;
            if (!FinancialNumber::isValid($h, 2) || bccomp(Dec::of($h), '0.5', 2) < 0 || bccomp(Dec::of($h), '24', 2) > 0) {
                $errors['hours'] = ['hours_invalid', 'Hours must be from 0.5 to 24.'];
            } else {
                $hours = Dec::of($h);
            }
        } elseif ($kind === 'holiday' || $kind === 'sick') {
            if ($e = MoneyField::check($input['amount'] ?? '0', 'amount', 'the pay for this day', '100000', required: false, positive: false)) {
                $errors['amount'] = $e;
            } else {
                $amount = Dec::of($input['amount'] ?? '0');
            }
        }
        if (isset($input['note']) && $input['note'] !== null && (!is_string($input['note']) || mb_strlen($input['note']) > 100 || Text::containsCode($input['note']))) {
            $errors['note'] = ['note_invalid', 'Use plain text, at most 100 characters.'];
        }
        if ($errors) {
            Reject::fail($errors);
        }

        return DB::transaction(function () use ($date, $kind, $hours, $amount, $input) {
            $day = WorkDay::query()->where('work_date', $date)->first();
            $old = $day ? MoneyAudit::values($day) : [];
            $day ??= new WorkDay(['uuid' => RecordId::make($input['id'] ?? null), 'work_date' => $date]);
            $day->fill(['kind' => $kind, 'hours' => $hours, 'amount' => $amount, 'note' => $input['note'] ?? null])->save();
            MoneyAudit::record($old ? 'updated' : 'created', $day, $old, MoneyAudit::values($day));

            return $day->refresh();
        });
    }

    public function deleteDay(string $date): void
    {
        $day = WorkDay::query()->where('work_date', $date)->firstOrFail();
        DB::transaction(function () use ($day) {
            MoneyAudit::record('deleted', $day, MoneyAudit::values($day));
            $day->delete();
        });
    }

    /** @return array{0: WorkBonus, 1: bool} */
    public function saveBonus(?WorkBonus $bonus, array $input): array
    {
        if (!$bonus && ($existing = RecordId::existing(WorkBonus::class, $input['id'] ?? null))) {
            return [$existing, false];
        }

        $date   = $input['bonus_date'] ?? $bonus?->bonus_date?->format('Y-m-d');
        $amount = array_key_exists('amount', $input) ? $input['amount'] : $bonus?->amount;
        $errors = [];
        if ($e = RecordTime::date($date, $this->tz())) {
            $errors['bonus_date'] = $e;
        }
        if ($e = MoneyField::check($amount, 'amount', 'the bonus', '1000000')) {
            $errors['amount'] = $e;
        }
        if ($errors) {
            Reject::fail($errors);
        }

        $created = !$bonus;
        $bonus ??= new WorkBonus(['uuid' => RecordId::make($input['id'] ?? null)]);
        $old = $bonus->exists ? MoneyAudit::values($bonus) : [];
        $bonus->fill(['bonus_date' => $date, 'amount' => Dec::of($amount), 'note' => $input['note'] ?? $bonus->note])->save();
        MoneyAudit::record($created ? 'created' : 'updated', $bonus, $old, MoneyAudit::values($bonus));

        return [$bonus->refresh(), $created];
    }

    // ------------------------------------------------------------------ pay of a period

    /** @return array{days_worked: int, hours: string, overtime_h: string, work_pay: string, leave_pay: string, bonus: string, period_pay: string, has_rate: bool} */
    public function periodPay(string $from, string $to): array
    {
        $s   = $this->settings();
        $out = ['days_worked' => 0, 'hours' => '0.00', 'overtime_h' => '0.00', 'work_pay' => '0.00', 'leave_pay' => '0.00', 'bonus' => '0.00'];

        foreach (WorkDay::query()->whereBetween('work_date', [$from, $to])->get() as $d) {
            $calc = W::day($d->kind, $d->hours, $d->amount, $s);
            if ($d->kind === 'work') {
                $out['days_worked']++;
                $out['hours']      = bcadd($out['hours'], Dec::of($d->hours), 2);
                $out['overtime_h'] = bcadd($out['overtime_h'], $calc['overtime_h'], 2);
                $out['work_pay']   = bcadd($out['work_pay'], $calc['day_pay'], 2);
            } else {
                $out['leave_pay'] = bcadd($out['leave_pay'], $calc['day_pay'], 2);
            }
        }
        $out['bonus'] = Dec::of((string) WorkBonus::query()->whereBetween('bonus_date', [$from, $to])->sum('amount'));

        return [...$out, 'period_pay' => bcadd(bcadd($out['work_pay'], $out['leave_pay'], 2), $out['bonus'], 2), 'has_rate' => W::hasRate($s)];
    }

    // ------------------------------------------------------------------ salaries

    /** Default dates for "I got my salary": from the first unpaid day to the last unpaid saved day. */
    public function suggestSalary(): array
    {
        $unpaid = $this->unpaidDays();
        $from   = $unpaid[0] ?? null;
        $to     = $unpaid ? end($unpaid) : null;
        $pay    = $from ? $this->periodPay($from, $to) : null;
        $left   = $this->loanLeft();

        return [
            'from_date'  => $from,
            'to_date'    => $to,
            'unpaid_days'=> count($unpaid),
            'period_pay' => $pay['period_pay'] ?? '0.00',
            'loan_left'  => $left,
            'max_cut'    => W::maxCut($pay['period_pay'] ?? '0.00', $left, W::hasRate($this->settings())),
        ];
    }

    /** @return array{0: Salary, 1: bool} */
    public function saveSalary(?Salary $salary, array $input): array
    {
        if (!$salary && ($existing = RecordId::existing(Salary::class, $input['id'] ?? null))) {
            return [$existing, false];
        }

        $from = $input['from_date'] ?? $salary?->from_date?->format('Y-m-d');
        $to   = $input['to_date'] ?? $salary?->to_date?->format('Y-m-d');
        $errors = [];

        foreach (['from_date' => $from, 'to_date' => $to] as $f => $v) {
            if ($e = RecordTime::date($v, $this->tz())) {
                $errors[$f] = $e;
            }
        }
        if (!$errors && $from > $to) {
            $errors['to_date'] = ['to_before_from', 'The last day must be on or after the first day.'];
        }

        if (!$errors) {
            $overlap = Salary::query()->when($salary, fn ($q) => $q->whereKeyNot($salary->id))
                ->where('from_date', '<=', $to)->where('to_date', '>=', $from)->orderBy('from_date')->first();
            if ($overlap) {
                $next = (new \DateTimeImmutable($overlap->to_date->format('Y-m-d')))->modify('+1 day')->format('Y-m-d');
                $errors['from_date'] = ['salary_overlap', "Salary for {$overlap->from_date->format('Y-m-d')} – {$overlap->to_date->format('Y-m-d')} is already saved. Start from {$next} or later."];
            } elseif (!WorkDay::query()->whereBetween('work_date', [$from, $to])->whereIn('kind', W::PAID_KINDS)->exists()) {
                $errors['from_date'] = ['salary_no_days', 'There is no work day to be paid in these days.'];
            }
        }

        $amount = array_key_exists('amount_received', $input) ? $input['amount_received'] : $salary?->amount_received;
        if ($amount !== null && $amount !== '' && ($e = MoneyField::check($amount, 'amount_received', 'the salary received', '10000000', required: false, positive: false))) {
            $errors['amount_received'] = $e;
        }
        $receivedOn = array_key_exists('received_on', $input) ? $input['received_on'] : $salary?->received_on?->format('Y-m-d');
        if ($receivedOn !== null && $receivedOn !== '' && ($e = RecordTime::date($receivedOn, $this->tz()))) {
            $errors['received_on'] = $e;
        }

        // the loan cut: at most min(period pay, loan left without this salary's own cut)
        $cut = array_key_exists('loan_cut', $input) ? $input['loan_cut'] : $salary?->loanCut?->amount;
        $cut = ($cut === null || $cut === '') ? '0.00' : $cut;
        if (!$errors && ($e = MoneyField::check($cut, 'loan_cut', 'the loan cut', '10000000', required: false, positive: false))) {
            $errors['loan_cut'] = $e;
        }
        $pay = !$errors ? $this->periodPay($from, $to) : null;
        if (!$errors && bccomp(Dec::of($cut), '0', 2) > 0) {
            $left = $this->loanLeft($salary?->id);
            $max  = W::maxCut($pay['period_pay'], $left, $pay['has_rate']);
            if (bccomp(Dec::of($cut), $max, 2) > 0) {
                $errors['loan_cut'] = $pay['has_rate'] && bccomp($pay['period_pay'], $left, 2) < 0
                    ? ['loan_cut_more_than_pay', "At most {$max} can be cut from this salary. The other " . bcsub($left, $max, 2) . ' stays as loan.']
                    : ['loan_cut_more_than_loan', "Only {$left} loan is left."];
            }
        }
        if ($errors) {
            Reject::fail($errors);
        }

        return DB::transaction(function () use ($salary, $input, $from, $to, $amount, $receivedOn, $cut) {
            $created = !$salary;
            $salary ??= new Salary(['uuid' => RecordId::make($input['id'] ?? null)]);
            $old = $salary->exists ? MoneyAudit::values($salary) : [];
            $salary->fill([
                'from_date' => $from, 'to_date' => $to,
                'amount_received' => ($amount === null || $amount === '') ? null : Dec::of($amount),
                'received_on' => $receivedOn ?: null,
                'note' => $input['note'] ?? $salary->note,
            ])->save();

            LoanEntry::query()->where('salary_id', $salary->id)->where('type', 'cut')->delete();
            if (bccomp(Dec::of($cut), '0', 2) > 0) {
                LoanEntry::create(['uuid' => RecordId::make(null), 'type' => 'cut', 'amount' => Dec::of($cut), 'entry_date' => $receivedOn ?: $to, 'salary_id' => $salary->id]);
            }
            MoneyAudit::record($created ? 'created' : 'updated', $salary, $old, [...MoneyAudit::values($salary), 'loan_cut' => Dec::of($cut)]);

            return [$salary->refresh(), $created];
        });
    }

    public function deleteSalary(Salary $salary): void
    {
        DB::transaction(function () use ($salary) {
            MoneyAudit::record('deleted', $salary, MoneyAudit::values($salary));
            $salary->delete();   // its loan cut goes with it (cascade)
        });
    }

    /** Salary with its pay, cut and money in hand. */
    public function salarySummary(Salary $s): array
    {
        $pay = $this->periodPay($s->from_date->format('Y-m-d'), $s->to_date->format('Y-m-d'));
        $cut = Dec::of($s->loanCut?->amount);

        return ['period_pay' => $pay['period_pay'], 'loan_cut' => $cut, 'in_hand' => W::inHand($pay['period_pay'], $cut), 'has_rate' => $pay['has_rate']];
    }

    /** @return list<string> dates of work, holiday and sick days not in the future and not inside any salary */
    public function unpaidDays(): array
    {
        $salaries = Salary::query()->get(['from_date', 'to_date']);
        $today    = (new \DateTimeImmutable('now', new \DateTimeZone($this->tz())))->format('Y-m-d');

        return WorkDay::query()->whereIn('kind', W::PAID_KINDS)->where('work_date', '<=', $today)->orderBy('work_date')->get()
            ->map(fn ($d) => $d->work_date->format('Y-m-d'))
            ->reject(fn ($date) => $salaries->contains(fn ($s) => $date >= $s->from_date->format('Y-m-d') && $date <= $s->to_date->format('Y-m-d')))
            ->values()->all();
    }

    // ------------------------------------------------------------------ loans

    public function loanLeft(?int $exceptSalaryId = null): string
    {
        $left = '0.00';
        foreach (LoanEntry::query()->get() as $e) {
            if ($exceptSalaryId && (int) $e->salary_id === $exceptSalaryId) {
                continue;
            }
            $left = $e->type === 'take' ? bcadd($left, Dec::of($e->amount), 2) : bcsub($left, Dec::of($e->amount), 2);
        }

        return $left;
    }

    /** take or repay. A cut is made only through a salary. @return array{0: LoanEntry, 1: bool} */
    public function saveLoan(?LoanEntry $entry, array $input): array
    {
        if (!$entry && ($existing = RecordId::existing(LoanEntry::class, $input['id'] ?? null))) {
            return [$existing, false];
        }
        if ($entry?->type === 'cut') {
            Reject::fail(['type' => ['cut_through_salary', 'A loan cut is changed on its salary.']]);
        }

        $type   = $input['type'] ?? $entry?->type;
        $amount = array_key_exists('amount', $input) ? $input['amount'] : $entry?->amount;
        $date   = $input['entry_date'] ?? $entry?->entry_date?->format('Y-m-d');
        $errors = [];
        if (!in_array($type, ['take', 'repay'], true)) {
            $errors['type'] = ['loan_type_invalid', 'Choose take (I borrowed) or repay (I paid back).'];
        }
        if ($e = MoneyField::check($amount, 'amount', 'the amount', '10000000')) {
            $errors['amount'] = $e;
        }
        if ($e = RecordTime::date($date, $this->tz())) {
            $errors['entry_date'] = $e;
        }
        if (!$errors) {
            // the loan left can never go below 0
            $left = $this->loanLeft();
            if ($entry) {
                $left = $entry->type === 'take' ? bcsub($left, Dec::of($entry->amount), 2) : bcadd($left, Dec::of($entry->amount), 2);
            }
            $after = $type === 'take' ? bcadd($left, Dec::of($amount), 2) : bcsub($left, Dec::of($amount), 2);
            if (bccomp($after, '0', 2) < 0) {
                $errors['amount'] = ['repay_more_than_loan', "Only {$left} loan is left."];
            }
        }
        if ($errors) {
            Reject::fail($errors);
        }

        $created = !$entry;
        $entry ??= new LoanEntry(['uuid' => RecordId::make($input['id'] ?? null)]);
        $old = $entry->exists ? MoneyAudit::values($entry) : [];
        $entry->fill(['type' => $type, 'amount' => Dec::of($amount), 'entry_date' => $date, 'note' => $input['note'] ?? $entry->note])->save();
        MoneyAudit::record($created ? 'created' : 'updated', $entry, $old, MoneyAudit::values($entry));

        return [$entry->refresh(), $created];
    }

    public function deleteLoan(LoanEntry $entry): void
    {
        if ($entry->type === 'cut') {
            Reject::fail(['type' => ['cut_through_salary', 'A loan cut is removed on its salary.']]);
        }
        if ($entry->type === 'take' && bccomp(bcsub($this->loanLeft(), Dec::of($entry->amount), 2), '0', 2) < 0) {
            Reject::fail(['amount' => ['loan_already_repaid', 'This loan is already (partly) paid back. Remove the repayments first.']]);
        }
        DB::transaction(function () use ($entry) {
            MoneyAudit::record('deleted', $entry, MoneyAudit::values($entry));
            $entry->delete();
        });
    }

    // ------------------------------------------------------------------ spending

    /** @return array{0: SpendingEntry, 1: bool} */
    public function saveSpending(?SpendingEntry $entry, array $input): array
    {
        if (!$entry && ($existing = RecordId::existing(SpendingEntry::class, $input['id'] ?? null))) {
            return [$existing, false];
        }

        $date     = $input['spent_on'] ?? $entry?->spent_on?->format('Y-m-d');
        $category = $input['category'] ?? $entry?->category;
        $amount   = array_key_exists('amount', $input) ? $input['amount'] : $entry?->amount;
        $currency = isset($input['currency']) && is_string($input['currency']) ? strtoupper(trim($input['currency'])) : ($entry?->currency ?? $this->currency());
        $errors   = [];
        if ($e = RecordTime::date($date, $this->tz())) {
            $errors['spent_on'] = $e;
        }
        if (!in_array($category, W::CATEGORIES, true)) {
            $errors['category'] = ['category_invalid', 'Choose a spending category from the list.'];
        }
        if ($e = MoneyField::check($amount, 'amount', 'the amount spent', '1000000')) {
            $errors['amount'] = $e;
        }
        if (!in_array($currency, SenderOptions::CURRENCIES, true)) {
            $errors['currency'] = ['currency_invalid', 'Choose a currency from the list.'];
        }
        $note = array_key_exists('note', $input) ? $input['note'] : $entry?->note;
        if ($note !== null && (!is_string($note) || mb_strlen($note) > 100 || Text::containsCode($note))) {
            $errors['note'] = ['note_invalid', 'Use plain text, at most 100 characters.'];
        }
        if ($errors) {
            Reject::fail($errors);
        }

        $created = !$entry;
        $entry ??= new SpendingEntry(['uuid' => RecordId::make($input['id'] ?? null)]);
        $entry->fill(['spent_on' => $date, 'category' => $category, 'amount' => Dec::of($amount), 'currency' => $currency, 'note' => $note ?: null])->save();

        return [$entry->refresh(), $created];
    }

    private function tz(): string
    {
        return $this->current->get()->timezone;
    }
}
