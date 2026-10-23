<?php

namespace App\Http\Controllers\Api\V1\Work;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\LoanEntry;
use App\Models\Salary;
use App\Models\SpendingEntry;
use App\Models\WorkBonus;
use App\Models\WorkDay;
use App\Models\WorkSetting;
use App\Services\Work\WorkService;
use App\Support\Api\Paging;
use App\Support\Money\Dec;
use App\Support\Reports\ReportPeriod;
use App\Support\Tenancy\CurrentBusiness;
use App\Support\Time\BusinessClock;
use App\Support\Validation\Reject;
use App\Support\Work\WorkCalculator as W;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Worker tools, PRIVATE to the logged-in user (Money Rules 4). All dates are local dates (YYYY-MM-DD).
 *
 * GET|PUT  /work/settings                     hourly_rate (optional), currency, day_hours, ot_multiplier
 * GET      /work/days?from=&to=               days with their pay
 * PUT      /work/days/{date}                  one entry per day: kind work|holiday|sick|off, hours, amount, note
 * DELETE   /work/days/{date}
 * GET|POST /work/bonuses, PATCH|DELETE /work/bonuses/{id}
 * GET      /work/summary?from=&to=            hours, overtime, pay of the period
 * GET      /work/salaries, GET /work/salaries/suggest, POST /work/salaries, PATCH|DELETE /work/salaries/{id}
 * GET|POST /work/loans, PATCH|DELETE /work/loans/{id}     (take | repay; cuts come from salaries)
 * GET|POST /work/spending, PATCH|DELETE /work/spending/{id}, GET /work/spending/summary
 */
class WorkController extends Controller
{
    public function __construct(private WorkService $service, private CurrentBusiness $current)
    {
    }

    // ---- settings

    public function settings(): JsonResponse
    {
        return ApiResponse::success($this->presentSettings($this->service->settings()));
    }

    public function saveSettings(Request $request): JsonResponse
    {
        return ApiResponse::success($this->presentSettings($this->service->saveSettings($request->only(['hourly_rate', 'currency', 'day_hours', 'ot_multiplier']))), 'Saved.');
    }

    // ---- days

    public function days(Request $request): JsonResponse
    {
        [$from, $to] = $this->range($request);
        $s = $this->service->settings();

        $days = WorkDay::query()->when($from, fn ($q) => $q->where('work_date', '>=', $from))->when($to, fn ($q) => $q->where('work_date', '<=', $to))
            ->orderBy('work_date')->get()->map(fn ($d) => $this->presentDay($d, $s))->all();

        return ApiResponse::success(['items' => $days]);
    }

    public function saveDay(Request $request, string $date): JsonResponse
    {
        $day = $this->service->saveDay($date, $request->only(['id', 'kind', 'hours', 'amount', 'note']));

        return ApiResponse::success($this->presentDay($day, $this->service->settings()), 'Saved.');
    }

    public function deleteDay(string $date): JsonResponse
    {
        $this->service->deleteDay($date);

        return ApiResponse::success(null, 'Deleted.');
    }

    // ---- bonuses

    public function bonuses(Request $request): JsonResponse
    {
        [$from, $to] = $this->range($request);
        $items = WorkBonus::query()->when($from, fn ($q) => $q->where('bonus_date', '>=', $from))->when($to, fn ($q) => $q->where('bonus_date', '<=', $to))
            ->orderBy('bonus_date')->get()->map(fn ($b) => $this->presentBonus($b))->all();

        return ApiResponse::success(['items' => $items]);
    }

    public function storeBonus(Request $request): JsonResponse
    {
        [$b, $created] = $this->service->saveBonus(null, $request->only(['id', 'bonus_date', 'amount', 'note']));

        return ApiResponse::success($this->presentBonus($b), $created ? 'Saved.' : 'Already saved.', $created ? 201 : 200);
    }

    public function updateBonus(Request $request, string $id): JsonResponse
    {
        [$b] = $this->service->saveBonus(WorkBonus::findByUuidOrFail($id), $request->only(['bonus_date', 'amount', 'note']));

        return ApiResponse::success($this->presentBonus($b), 'Saved.');
    }

    public function deleteBonus(string $id): JsonResponse
    {
        WorkBonus::findByUuidOrFail($id)->delete();

        return ApiResponse::success(null, 'Deleted.');
    }

    public function summary(Request $request): JsonResponse
    {
        [$from, $to] = $this->range($request, required: true);

        return ApiResponse::success(['from' => $from, 'to' => $to, 'currency' => $this->service->currency(), ...$this->service->periodPay($from, $to)]);
    }

    // ---- salaries

    public function salaries(Request $request): JsonResponse
    {
        $page = Paging::paginate(Salary::query()->with('loanCut')->orderByDesc('to_date')->orderByDesc('id'), $request);

        return ApiResponse::success([
            ...Paging::shape($page, fn ($s) => $this->presentSalary($s)),
            'unpaid_days' => count($this->service->unpaidDays()),
        ]);
    }

    public function suggestSalary(): JsonResponse
    {
        return ApiResponse::success($this->service->suggestSalary());
    }

    public function storeSalary(Request $request): JsonResponse
    {
        [$s, $created] = $this->service->saveSalary(null, $request->only(['id', 'from_date', 'to_date', 'amount_received', 'received_on', 'loan_cut', 'note']));

        return ApiResponse::success($this->presentSalary($s), $created ? 'Saved.' : 'Already saved.', $created ? 201 : 200);
    }

    public function updateSalary(Request $request, string $id): JsonResponse
    {
        [$s] = $this->service->saveSalary(Salary::findByUuidOrFail($id), $request->only(['from_date', 'to_date', 'amount_received', 'received_on', 'loan_cut', 'note']));

        return ApiResponse::success($this->presentSalary($s), 'Saved.');
    }

    public function deleteSalary(string $id): JsonResponse
    {
        $this->service->deleteSalary(Salary::findByUuidOrFail($id));

        return ApiResponse::success(null, 'Deleted.');
    }

    // ---- loans

    public function loans(Request $request): JsonResponse
    {
        $page = Paging::paginate(LoanEntry::query()->orderByDesc('entry_date')->orderByDesc('id'), $request);

        return ApiResponse::success([
            ...Paging::shape($page, fn ($e) => $this->presentLoan($e)),
            'loan_left' => $this->service->loanLeft(),
            'currency' => $this->service->currency(),
        ]);
    }

    public function storeLoan(Request $request): JsonResponse
    {
        [$e, $created] = $this->service->saveLoan(null, $request->only(['id', 'type', 'amount', 'entry_date', 'note']));

        return ApiResponse::success([...$this->presentLoan($e), 'loan_left' => $this->service->loanLeft()], $created ? 'Saved.' : 'Already saved.', $created ? 201 : 200);
    }

    public function updateLoan(Request $request, string $id): JsonResponse
    {
        [$e] = $this->service->saveLoan(LoanEntry::findByUuidOrFail($id), $request->only(['type', 'amount', 'entry_date', 'note']));

        return ApiResponse::success([...$this->presentLoan($e), 'loan_left' => $this->service->loanLeft()], 'Saved.');
    }

    public function deleteLoan(string $id): JsonResponse
    {
        $this->service->deleteLoan(LoanEntry::findByUuidOrFail($id));

        return ApiResponse::success(['loan_left' => $this->service->loanLeft()], 'Deleted.');
    }

    // ---- spending

    public function spending(Request $request): JsonResponse
    {
        [$from, $to] = $this->range($request);
        $query = SpendingEntry::query()->when($from, fn ($q) => $q->where('spent_on', '>=', $from))->when($to, fn ($q) => $q->where('spent_on', '<=', $to));
        if (in_array($request->query('category'), W::CATEGORIES, true)) {
            $query->where('category', $request->query('category'));
        }

        return ApiResponse::success(['items' => $query->orderByDesc('spent_on')->orderByDesc('id')->get()->map(fn ($e) => $this->presentSpending($e))->all()]);
    }

    public function storeSpending(Request $request): JsonResponse
    {
        [$e, $created] = $this->service->saveSpending(null, $request->only(['id', 'spent_on', 'category', 'amount', 'currency', 'note']));

        return ApiResponse::success($this->presentSpending($e), $created ? 'Saved.' : 'Already saved.', $created ? 201 : 200);
    }

    public function updateSpending(Request $request, string $id): JsonResponse
    {
        [$e] = $this->service->saveSpending(SpendingEntry::findByUuidOrFail($id), $request->only(['spent_on', 'category', 'amount', 'currency', 'note']));

        return ApiResponse::success($this->presentSpending($e), 'Saved.');
    }

    public function deleteSpending(string $id): JsonResponse
    {
        SpendingEntry::findByUuidOrFail($id)->delete();

        return ApiResponse::success(null, 'Deleted.');
    }

    /** Totals per month, per currency, per category (Money Rules 4.3). */
    public function spendingSummary(Request $request): JsonResponse
    {
        $period = ReportPeriod::fromQuery($request->query(), BusinessClock::for($this->current->get()));
        $query  = SpendingEntry::query();
        if ($period->fromDate) {
            $query->where('spent_on', '>=', $period->fromDate);
        }
        if ($period->toDate) {
            $query->where('spent_on', '<=', $period->toDate);
        }

        $months = [];
        foreach ($query->orderBy('spent_on')->get() as $e) {
            $m = $e->spent_on->format('Y-m');
            $months[$m][$e->currency]['total'] = bcadd($months[$m][$e->currency]['total'] ?? '0.00', Dec::of($e->amount), 2);
            $months[$m][$e->currency]['by_category'][$e->category] = bcadd($months[$m][$e->currency]['by_category'][$e->category] ?? '0.00', Dec::of($e->amount), 2);
        }
        krsort($months);

        $out = [];
        foreach ($months as $m => $currencies) {
            ksort($currencies);
            $out[] = ['month' => $m, 'by_currency' => array_map(fn ($c, $v) => ['currency' => $c, ...$v], array_keys($currencies), $currencies)];
        }

        return ApiResponse::success([...$period->toArray(), 'months' => $out]);
    }

    // ---- presentation

    private function presentSettings(?WorkSetting $s): array
    {
        return [
            'hourly_rate'   => $s?->hourly_rate !== null ? Dec::money($s->hourly_rate) : null,
            'currency'      => $this->service->currency(),
            'day_hours'     => Dec::money($s?->day_hours ?? 8),
            'ot_multiplier' => Dec::money($s?->ot_multiplier ?? '1.50'),
            'has_rate'      => W::hasRate($s),
        ];
    }

    private function presentDay(WorkDay $d, ?WorkSetting $s): array
    {
        $calc = W::day($d->kind, $d->hours, $d->amount, $s);

        return ['id' => $d->uuid, 'work_date' => $d->work_date->format('Y-m-d'), 'kind' => $d->kind, 'hours' => Dec::money($d->hours),
            'amount' => $d->amount !== null ? Dec::money($d->amount) : null, 'note' => $d->note, ...$calc];
    }

    private function presentBonus(WorkBonus $b): array
    {
        return ['id' => $b->uuid, 'bonus_date' => $b->bonus_date->format('Y-m-d'), 'amount' => Dec::money($b->amount), 'note' => $b->note];
    }

    private function presentSalary(Salary $s): array
    {
        return ['id' => $s->uuid, 'from_date' => $s->from_date->format('Y-m-d'), 'to_date' => $s->to_date->format('Y-m-d'),
            'amount_received' => $s->amount_received !== null ? Dec::money($s->amount_received) : null,
            'received_on' => $s->received_on?->format('Y-m-d'), 'note' => $s->note, ...$this->service->salarySummary($s)];
    }

    private function presentLoan(LoanEntry $e): array
    {
        $salary = $e->salary_id ? Salary::query()->find($e->salary_id) : null;

        return ['id' => $e->uuid, 'type' => $e->type, 'amount' => Dec::money($e->amount), 'entry_date' => $e->entry_date->format('Y-m-d'), 'salary_id' => $salary?->uuid, 'note' => $e->note];
    }

    private function presentSpending(SpendingEntry $e): array
    {
        return ['id' => $e->uuid, 'spent_on' => $e->spent_on->format('Y-m-d'), 'category' => $e->category, 'amount' => Dec::money($e->amount), 'currency' => $e->currency, 'note' => $e->note];
    }

    /** @return array{0: ?string, 1: ?string} */
    private function range(Request $request, bool $required = false): array
    {
        $from = $request->query('from');
        $to   = $request->query('to');
        $zone = $this->current->get()->timezone;

        foreach (['from' => $from, 'to' => $to] as $field => $value) {
            if ($value === null) {
                if ($required) {
                    Reject::fail([$field => ['date_invalid', 'Choose a date like 2026-10-15.']]);
                }

                continue;
            }

            // Validate the calendar date, not only the YYYY-MM-DD shape.
            // Future filter bounds are valid for an empty report.
            if ($error = \App\Support\Records\RecordTime::date($value, $zone, allowFuture: true)) {
                Reject::fail([$field => $error]);
            }
        }

        if ($from !== null && $to !== null && $from > $to) {
            Reject::fail(['to' => ['date_order_invalid', 'The ending date cannot be earlier than the starting date.']]);
        }

        return [$from, $to];
    }
}
