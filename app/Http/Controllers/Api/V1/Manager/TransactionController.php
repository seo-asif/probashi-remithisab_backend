<?php

namespace App\Http\Controllers\Api\V1\Manager;

use App\Http\Controllers\Controller;
use App\Http\Requests\Manager\TransactionRequest;
use App\Http\Resources\Manager\PaymentResource;
use App\Http\Resources\Manager\TransactionResource;
use App\Http\Responses\ApiResponse;
use App\Models\BdtPayment;
use App\Models\Customer;
use App\Models\ManagerTransaction;
use App\Services\Manager\TransactionService;
use App\Support\Api\EditGuard;
use App\Support\Api\Paging;
use App\Support\Manager\ManagerOptions;
use App\Support\Reports\ReportPeriod;
use App\Support\Tenancy\CurrentBusiness;
use App\Support\Time\BusinessClock;
use App\Support\Validation\Reject;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET    /manager-transactions                     list (?q= ?customer_id= ?status= ?currency= ?receive_method= ?owed=1 + period, ?trashed=only)
 * POST   /manager-transactions                     create (send "id": the phone's UUID, so a retry never saves twice)
 * GET    /manager-transactions/{id}                with its payments
 * PATCH  /manager-transactions/{id}                change (optional base_updated_at for offline edits)
 * PATCH  /manager-transactions/{id}/status         quick status change
 * DELETE /manager-transactions/{id}                soft delete (payments are kept)
 * POST   /manager-transactions/{id}/restore
 * POST   /manager-transactions/{id}/payments       "+ টাকা পাঠালাম": { id, amount_bdt, paid_on?, note? }
 * POST   /manager-transactions/{id}/payments/{paymentId}/reverse   correct a wrong payment
 */
class TransactionController extends Controller
{
    public function __construct(private TransactionService $service, private CurrentBusiness $current)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $query = ManagerTransaction::query()->with(['customer', 'payments']);

        if ($request->query('trashed') === 'only') {
            $query->onlyTrashed();
        }

        ReportPeriod::fromQuery($request->query(), BusinessClock::for($this->current->get()))->apply($query, 'occurred_at');

        if ($request->filled('customer_id')) {
            $customer = Customer::query()->withTrashed()->where('uuid', (string) $request->query('customer_id'))->first();
            $query->where('customer_id', $customer?->id ?? 0);
        }
        foreach (['status' => ManagerOptions::STATUSES, 'receive_method' => ManagerOptions::RECEIVE_METHODS] as $field => $allowed) {
            if ($request->filled($field)) {
                $query->where($field, ManagerOptions::canonical((string) $request->query($field), $allowed));
            }
        }
        if ($request->filled('currency')) {
            $query->where('currency', strtoupper((string) $request->query('currency')));
        }
        if ($request->boolean('owed')) {
            $query->where('status', '<>', ManagerOptions::STATUS_PENDING)->whereColumn('paid_bdt', '<', 'obligation_bdt');
        }
        if (is_string($q = $request->query('q')) && trim($q) !== '') {
            $like = Paging::like($q);
            $query->where(fn ($w) => $w->where('customer_name', 'like', $like)->orWhere('pay_to', 'like', $like)
                ->orWhereHas('customer', fn ($c) => $c->withTrashed()->where(fn ($x) => $x->where('name', 'like', $like)->orWhere('phone', 'like', $like))));
        }

        $query->orderByDesc('occurred_at')->orderByDesc('id');
        $page = Paging::paginate($query, $request);

        return ApiResponse::success(Paging::shape($page, fn ($t) => TransactionResource::make($t)->resolve($request)));
    }

    public function store(TransactionRequest $request): JsonResponse
    {
        [$tx, $created] = $this->service->create($request->validated());

        return $this->answer($request, $tx, $created ? 'Transaction saved.' : 'Already saved.', $created ? 201 : 200);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return $this->answer($request, ManagerTransaction::findByUuidOrFail($id, withTrashed: true));
    }

    public function update(TransactionRequest $request, string $id): JsonResponse
    {
        $tx = ManagerTransaction::findByUuidOrFail($id);
        EditGuard::assertFresh($tx, $request->input('base_updated_at'), fn ($t) => $this->present($request, $t));

        return $this->answer($request, $this->service->update($tx, $request->safe()->except(['base_updated_at', 'id'])), 'Transaction updated.');
    }

    public function status(Request $request, string $id): JsonResponse
    {
        $status = ManagerOptions::canonical($request->input('status'), ManagerOptions::STATUSES);
        if (!in_array($status, ManagerOptions::STATUSES, true)) {
            Reject::fail(['status' => ['status_invalid', 'Choose a status: currency_pending, currency_received or bdt_sent.']]);
        }

        return $this->answer($request, $this->service->changeStatus(ManagerTransaction::findByUuidOrFail($id), $status), 'Status updated.');
    }

    public function destroy(string $id): JsonResponse
    {
        $this->service->delete(ManagerTransaction::findByUuidOrFail($id));

        return ApiResponse::success(null, 'Transaction deleted.');
    }

    public function restore(Request $request, string $id): JsonResponse
    {
        return $this->answer($request, $this->service->restore(ManagerTransaction::findByUuidOrFail($id, withTrashed: true)), 'Transaction restored.');
    }

    public function addPayment(Request $request, string $id): JsonResponse
    {
        $tx = ManagerTransaction::findByUuidOrFail($id);
        [$payment, $created] = $this->service->addPayment($tx, $request->only(['id', 'amount_bdt', 'paid_on', 'note']));

        return ApiResponse::success([
            'payment'     => PaymentResource::make($payment)->resolve($request),
            'transaction' => $this->present($request, $tx->refresh()),
        ], $created ? 'Payment saved.' : 'Already saved.', $created ? 201 : 200);
    }

    public function reversePayment(Request $request, string $id, string $paymentId): JsonResponse
    {
        $tx       = ManagerTransaction::findByUuidOrFail($id);
        $payment  = BdtPayment::findByUuidOrFail($paymentId);
        $reversal = $this->service->reversePayment($tx, $payment, $request->only(['id', 'note']));

        return ApiResponse::success([
            'payment'     => PaymentResource::make($reversal)->resolve($request),
            'transaction' => $this->present($request, $tx->refresh()),
        ], 'Payment reversed.', 201);
    }

    private function present(Request $request, ManagerTransaction $tx): array
    {
        return TransactionResource::make($tx->load(['customer', 'payments']))->resolve($request);
    }

    private function answer(Request $request, ManagerTransaction $tx, string $message = 'OK', int $status = 200): JsonResponse
    {
        return ApiResponse::success($this->present($request, $tx), $message, $status);
    }
}
