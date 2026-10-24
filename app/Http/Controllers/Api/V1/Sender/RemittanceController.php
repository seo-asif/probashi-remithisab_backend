<?php

namespace App\Http\Controllers\Api\V1\Sender;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sender\SenderRemittanceRequest;
use App\Http\Resources\Sender\SenderRemittanceResource;
use App\Http\Responses\ApiResponse;
use App\Models\SenderRecipient;
use App\Models\SenderRemittance;
use App\Services\Sender\SenderService;
use App\Support\Api\EditGuard;
use App\Support\Api\Paging;
use App\Support\Money\CurrencyRules;
use App\Support\Money\Dec;
use App\Support\Reports\ReportPeriod;
use App\Support\Sender\SenderCalculator;
use App\Support\Sender\SenderOptions;
use App\Support\Sender\SenderValidation;
use App\Support\Tenancy\CurrentBusiness;
use App\Support\Time\BusinessClock;
use App\Support\Validation\Reject;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * /sender-remittances. PRIVATE.
 * GET (?q= ?recipient_id= ?recipient_type=family|self ?currency= ?received=0|1 ?purpose= + period, ?trashed=only)
 * POST, GET|PATCH|DELETE /{id}, POST /{id}/restore, POST /{id}/received {received: true|false}, GET /{id}/copy-info
 */
class RemittanceController extends Controller
{
    public function __construct(private SenderService $service, private CurrentBusiness $current)
    {
    }

    public function options(): JsonResponse
    {
        $currencies = [];
        foreach (CurrencyRules::CURRENCIES as $currency) {
            $currencies[] = [
                'code' => $currency,
                'amount_max' => CurrencyRules::amountMax($currency),
                'rate_max' => CurrencyRules::rateMax($currency),
                'rate_decimals' => CurrencyRules::rateDecimals($currency),
            ];
        }

        return ApiResponse::success([
            'currencies' => $currencies,
            'methods' => SenderOptions::METHODS,
            'charge_types' => SenderOptions::CHARGE_TYPES,
            'purposes' => SenderOptions::PURPOSES,
            'notes_max' => SenderOptions::NOTES_MAX,
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $query = SenderRemittance::query()->with(['recipient', 'goal']);

        if ($request->query('trashed') === 'only') {
            $query->onlyTrashed();
        }
        ReportPeriod::fromQuery($request->query(), BusinessClock::for($this->current->get()))->apply($query, 'occurred_at');

        if ($request->filled('recipient_id')) {
            $r = SenderRecipient::query()->withTrashed()->where('uuid', (string) $request->query('recipient_id'))->first();
            $query->where('recipient_id', $r?->id ?? 0);
        }
        if (in_array($request->query('recipient_type'), SenderOptions::RECIPIENT_TYPES, true)) {
            $query->where('recipient_type', $request->query('recipient_type'));
        }
        if ($request->filled('currency')) {
            $query->where('currency', strtoupper((string) $request->query('currency')));
        }
        if ($request->has('received')) {
            $query->where('received', $request->boolean('received'));
        }
        if (in_array($request->query('purpose'), SenderOptions::PURPOSES, true)) {
            $query->where('purpose', $request->query('purpose'));
        }
        if (is_string($q = $request->query('q')) && trim($q) !== '') {
            $like = Paging::like($q);
            $query->where(fn ($w) => $w->where('recipient_name', 'like', $like)->orWhere('notes', 'like', $like)->orWhere('method', 'like', $like));
        }

        $page = Paging::paginate($query->orderByDesc('occurred_at')->orderByDesc('id'), $request);

        return ApiResponse::success(Paging::shape($page, fn ($r) => SenderRemittanceResource::make($r)->resolve($request)));
    }

    public function preview(Request $request): JsonResponse
    {
        $currency = is_string($request->input('currency'))
            ? strtoupper(trim((string) $request->input('currency')))
            : '';

        if (!CurrencyRules::supported($currency)) {
            Reject::fail([
                'currency' => ['currency_invalid', 'Choose a currency from the list.'],
            ]);
        }

        $money = [
            'amount' => $request->input('amount'),
            'rate' => $request->input('rate'),
            'charge' => $request->input('charge', '0'),
            'charge_type' => $request->input('charge_type'),
            'incentive_pct' => $request->input('incentive_pct', '0'),
        ];

        if ($errors = SenderValidation::moneyErrors($money, $currency)) {
            Reject::fail($errors);
        }

        return ApiResponse::success([
            'currency' => $currency,
            ...SenderCalculator::breakdown(
                $money['amount'],
                $money['rate'],
                $money['charge'],
                (string) $money['charge_type'],
                $money['incentive_pct'],
            ),
        ]);
    }

    public function store(SenderRemittanceRequest $request): JsonResponse
    {
        [$r, $created] = $this->service->createRemittance($request->validated());

        return $this->answer($request, $r, $created ? 'Remittance saved.' : 'Already saved.', $created ? 201 : 200);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return $this->answer($request, SenderRemittance::findByUuidOrFail($id, withTrashed: true));
    }

    public function update(SenderRemittanceRequest $request, string $id): JsonResponse
    {
        $r = SenderRemittance::findByUuidOrFail($id);
        EditGuard::assertFresh($r, $request->input('base_updated_at'), fn ($x) => SenderRemittanceResource::make($x->load(['recipient', 'goal']))->resolve($request));

        return $this->answer($request, $this->service->updateRemittance($r, collect($request->validated())->except(['base_updated_at', 'id'])->all()), 'Remittance updated.');
    }

    public function received(Request $request, string $id): JsonResponse
    {
        $value = $request->input('received', true);
        if (!is_bool($value) && !in_array($value, [0, 1, '0', '1', 'true', 'false'], true)) {
            Reject::fail(['received' => ['received_invalid', 'Send received: true or false.']]);
        }

        return $this->answer($request, $this->service->markReceived(SenderRemittance::findByUuidOrFail($id), filter_var($value, FILTER_VALIDATE_BOOLEAN)), 'Saved.');
    }

    public function destroy(string $id): JsonResponse
    {
        $this->service->deleteRemittance(SenderRemittance::findByUuidOrFail($id));

        return ApiResponse::success(null, 'Remittance deleted.');
    }

    public function restore(Request $request, string $id): JsonResponse
    {
        return $this->answer($request, $this->service->restoreRemittance(SenderRemittance::findByUuidOrFail($id, withTrashed: true)), 'Remittance restored.');
    }

    /** "তথ্য কপি" (Copy info): the fields of the message. The app writes the text in Bangla. No secrets. */
    public function copyInfo(string $id): JsonResponse
    {
        $r     = SenderRemittance::findByUuidOrFail($id);
        $clock = BusinessClock::for($this->current->get());

        return ApiResponse::success([
            'recipient_name' => $r->recipient_name,
            'recipient_type' => $r->recipient_type,
            'family_bdt'     => Dec::money($r->family_bdt),
            'method'         => $r->method,
            'amount'         => Dec::money($r->amount),
            'currency'       => $r->currency,
            'occurred_at'    => BusinessClock::utcIso($r->occurred_at),
            'occurred_local' => $clock->toLocal($r->occurred_at)->format('Y-m-d H:i'),
        ]);
    }

    private function answer(Request $request, SenderRemittance $r, string $message = 'OK', int $status = 200): JsonResponse
    {
        return ApiResponse::success(SenderRemittanceResource::make($r->load(['recipient', 'goal']))->resolve($request), $message, $status);
    }
}
