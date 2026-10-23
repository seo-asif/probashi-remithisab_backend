<?php

namespace App\Http\Controllers\Api\V1\Student;

use App\Http\Controllers\Controller;
use App\Exceptions\ApiException;
use App\Http\Responses\ApiResponse;
use App\Models\StudentExpense;
use App\Models\StudentFee;
use App\Models\StudentMoneyIn;
use App\Models\StudentOpeningBalance;
use App\Services\Student\StudentService;
use App\Support\Api\Paging;
use App\Support\Money\Dec;
use App\Support\Records\RecordTime;
use App\Support\Tenancy\CurrentBusiness;
use App\Support\Time\BusinessClock;
use App\Support\Validation\Reject;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** User-private Student Mode. Every read/write uses existing business.context. */
final class StudentController extends Controller
{
    public function __construct(
        private StudentService $service,
        private CurrentBusiness $current,
    ) {
    }

    public function settings(): JsonResponse
    {
        return ApiResponse::success(['enabled' => $this->service->enabled()]);
    }

    public function saveSettings(Request $request): JsonResponse
    {
        $data = $request->validate(['enabled' => ['required', 'boolean']]);

        return ApiResponse::success([
            'enabled' => $this->service->setEnabled((bool) $data['enabled']),
        ], 'Saved.');
    }

    public function openings(): JsonResponse
    {
        return ApiResponse::success([
            'items' => StudentOpeningBalance::query()->orderBy('currency')
                ->get()->map(fn ($b) => $this->opening($b))->all(),
        ]);
    }

    public function saveOpening(Request $request): JsonResponse
    {
        $data = $request->validate([
            'currency' => ['required', 'string', 'max:3'],
            'amount' => ['required'],
        ]);

        return ApiResponse::success(
            $this->opening($this->service->saveOpening($data['currency'], $data['amount'])),
            'Opening balance saved.',
        );
    }

    public function deleteOpening(string $currency): JsonResponse
    {
        $this->service->removeOpening($currency);

        return ApiResponse::success(null, 'Opening balance removed.');
    }

    public function moneyIn(Request $request): JsonResponse
    {
        [$from, $to] = $this->range($request);
        $query = StudentMoneyIn::query()
            ->when($from, fn ($q) => $q->where('received_on', '>=', $from))
            ->when($to, fn ($q) => $q->where('received_on', '<=', $to))
            ->orderByDesc('received_on')->orderByDesc('id');

        $page = Paging::paginate($query, $request);

        return ApiResponse::success(Paging::shape($page, fn ($r) => $this->receipt($r)));
    }

    public function storeMoneyIn(Request $request): JsonResponse
    {
        [$r, $created] = $this->service->saveMoneyIn(null, $request->only([
            'id', 'from_id', 'from_recipient_id', 'from_name', 'from_relation', 'amount_bdt', 'rate_bdt_per_currency',
            'estimate_currency', 'method', 'received_on', 'note',
        ]));

        return ApiResponse::success($this->receipt($r), $created ? 'Money received saved.' : 'Already saved.', $created ? 201 : 200);
    }

    public function showMoneyIn(string $id): JsonResponse
    {
        return ApiResponse::success($this->receipt(StudentMoneyIn::findByUuidOrFail($id, withTrashed: true)));
    }

    public function updateMoneyIn(Request $request, string $id): JsonResponse
    {
        [$r] = $this->service->saveMoneyIn(StudentMoneyIn::findByUuidOrFail($id), $request->only([
            'from_id', 'from_recipient_id', 'from_name', 'from_relation', 'amount_bdt', 'rate_bdt_per_currency',
            'estimate_currency', 'method', 'received_on', 'note',
        ]));

        return ApiResponse::success($this->receipt($r), 'Updated.');
    }

    public function deleteMoneyIn(string $id): JsonResponse
    {
        $this->service->deleteMoneyIn(StudentMoneyIn::findByUuidOrFail($id));

        return ApiResponse::success(null, 'Deleted.');
    }

    public function restoreMoneyIn(string $id): JsonResponse
    {
        return ApiResponse::success(
            $this->receipt($this->service->restoreMoneyIn(StudentMoneyIn::findByUuidOrFail($id, withTrashed: true))),
            'Restored.',
        );
    }

    public function expenses(Request $request): JsonResponse
    {
        [$from, $to] = $this->range($request);
        $query = StudentExpense::query()
            ->when($from, fn ($q) => $q->where('spent_on', '>=', $from))
            ->when($to, fn ($q) => $q->where('spent_on', '<=', $to))
            ->orderByDesc('spent_on')->orderByDesc('id');

        $page = Paging::paginate($query, $request);

        return ApiResponse::success(Paging::shape($page, fn ($r) => $this->expense($r)));
    }

    public function storeExpense(Request $request): JsonResponse
    {
        [$r, $created] = $this->service->saveExpense(null, $request->only([
            'id', 'category', 'amount', 'currency', 'spent_on', 'note',
        ]));

        return ApiResponse::success($this->expense($r), $created ? 'Expense saved.' : 'Already saved.', $created ? 201 : 200);
    }

    public function showExpense(string $id): JsonResponse
    {
        return ApiResponse::success($this->expense(StudentExpense::findByUuidOrFail($id, withTrashed: true)));
    }

    public function updateExpense(Request $request, string $id): JsonResponse
    {
        [$r] = $this->service->saveExpense(StudentExpense::findByUuidOrFail($id), $request->only([
            'category', 'amount', 'currency', 'spent_on', 'note',
        ]));

        return ApiResponse::success($this->expense($r), 'Updated.');
    }

    public function deleteExpense(string $id): JsonResponse
    {
        $this->service->deleteExpense(StudentExpense::findByUuidOrFail($id));

        return ApiResponse::success(null, 'Deleted.');
    }

    public function restoreExpense(string $id): JsonResponse
    {
        return ApiResponse::success(
            $this->expense($this->service->restoreExpense(StudentExpense::findByUuidOrFail($id, withTrashed: true))),
            'Restored.',
        );
    }

    public function fees(Request $request): JsonResponse
    {
        $query = StudentFee::query()->orderBy('due_on')->orderBy('id');
        $page = Paging::paginate($query, $request);

        return ApiResponse::success(Paging::shape($page, fn ($r) => $this->fee($r)));
    }

    public function storeFee(Request $request): JsonResponse
    {
        $this->rejectRetiredFeeWrites();
    }

    public function showFee(string $id): JsonResponse
    {
        return ApiResponse::success($this->fee(StudentFee::findByUuidOrFail($id, withTrashed: true)));
    }

    public function updateFee(Request $request, string $id): JsonResponse
    {
        $this->rejectRetiredFeeWrites();
    }

    public function payFee(Request $request, string $id): JsonResponse
    {
        $this->rejectRetiredFeeWrites();
    }

    public function unpayFee(string $id): JsonResponse
    {
        $this->rejectRetiredFeeWrites();
    }

    public function deleteFee(string $id): JsonResponse
    {
        $this->rejectRetiredFeeWrites();
    }

    public function restoreFee(string $id): JsonResponse
    {
        $this->rejectRetiredFeeWrites();
    }

    /** Fee creation and mutation are retired in v1.1.290. Read-only legacy retrieval remains for historical export. */
    private function rejectRetiredFeeWrites(): never
    {
        throw new ApiException('STUDENT_FEES_RETIRED', 'Student fees are no longer supported.', 410);
    }

    public function summary(Request $request): JsonResponse
    {
        [$from, $to] = $this->range($request);
        $clock = BusinessClock::for($this->current->get());
        $today = $clock->toLocal(now())->format('Y-m-d');

        $from ??= substr($today, 0, 7) . '-01';
        $to ??= $today;

        if ($from > $to) {
            Reject::fail(['to' => ['date_order_invalid', 'The ending date cannot be earlier than the starting date.']]);
        }

        return ApiResponse::success($this->service->summary($from, $to));
    }

    private function receipt(StudentMoneyIn $r): array
    {
        return [
            'id' => $r->uuid,
            'from_id' => $r->from_recipient_id
                ? ($r->fromRecipient?->uuid ?? '__other__')
                : ($r->from_source_id ?? '__other__'),
            'from_recipient_id' => $r->from_recipient_id ? $r->fromRecipient?->uuid : null,
            'from_name' => $r->from_name,
            'amount_bdt' => Dec::money($r->amount_bdt),
            'rate_bdt_per_currency' => $r->rate_bdt_per_currency !== null ? Dec::money($r->rate_bdt_per_currency) : null,
            'estimate_currency' => $r->estimate_currency,
            'estimated_foreign_amount' => $r->rate_bdt_per_currency !== null
                ? bcdiv((string) $r->amount_bdt, (string) $r->rate_bdt_per_currency, 2)
                : null,
            'method' => $r->method,
            'received_on' => $r->received_on->format('Y-m-d'),
            'note' => $r->note,
            'deleted_at' => $r->deleted_at ? BusinessClock::utcIso($r->deleted_at) : null,
        ];
    }

    private function expense(StudentExpense $r): array
    {
        return [
            'id' => $r->uuid,
            'category' => $r->category,
            'amount' => Dec::money($r->amount),
            'currency' => $r->currency,
            'spent_on' => $r->spent_on->format('Y-m-d'),
            'note' => $r->note,
            'fee_id' => $r->student_fee_id ? $r->fee?->uuid : null,
            'deleted_at' => $r->deleted_at ? BusinessClock::utcIso($r->deleted_at) : null,
        ];
    }

    private function fee(StudentFee $r): array
    {
        return [
            'id' => $r->uuid,
            'name' => $r->name,
            'amount' => Dec::money($r->amount),
            'currency' => $r->currency,
            'due_on' => $r->due_on->format('Y-m-d'),
            'paid' => $r->paid_on !== null,
            'paid_on' => $r->paid_on?->format('Y-m-d'),
            'deleted_at' => $r->deleted_at ? BusinessClock::utcIso($r->deleted_at) : null,
        ];
    }

    private function opening(StudentOpeningBalance $r): array
    {
        return [
            'id' => $r->uuid,
            'currency' => $r->currency,
            'amount' => Dec::money($r->amount),
        ];
    }

    private function range(Request $request): array
    {
        $from = $request->query('from');
        $to = $request->query('to');
        $zone = $this->current->get()->timezone;

        foreach (['from' => $from, 'to' => $to] as $field => $value) {
            if ($value === null) {
                continue;
            }
            if ($error = RecordTime::date($value, $zone, allowFuture: true)) {
                Reject::fail([$field => $error]);
            }
        }

        if ($from !== null && $to !== null && $from > $to) {
            Reject::fail(['to' => ['date_order_invalid', 'The ending date cannot be earlier than the starting date.']]);
        }

        return [$from, $to];
    }
}
