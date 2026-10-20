<?php

namespace App\Http\Controllers\Api\V1\Manager;

use App\Http\Controllers\Controller;
use App\Http\Requests\Manager\CustomerRequest;
use App\Http\Resources\Manager\CustomerResource;
use App\Http\Responses\ApiResponse;
use App\Models\Customer;
use App\Models\ManagerTransaction;
use App\Services\Manager\CustomerService;
use App\Support\Api\EditGuard;
use App\Support\Api\Paging;
use App\Support\Money\Dec;
use App\Support\Security\IdentifierHasher;
use App\Support\Security\IdentifierNormalizer;
use App\Support\Time\BusinessClock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET    /customers                 list and search (?q=, ?sort=recent|name, ?trashed=only for recently deleted)
 * POST   /customers                 create (409 DUPLICATE_CUSTOMER or RESTORE_AVAILABLE when the number is known)
 * GET    /customers/{id}            detail with totals per currency
 * PATCH  /customers/{id}            change
 * DELETE /customers/{id}            soft delete (409 CUSTOMER_HAS_RECORDS while it has transactions)
 * POST   /customers/{id}/restore    undo a delete
 */
class CustomerController extends Controller
{
    public function __construct(
        private CustomerService $service,
        private IdentifierHasher $identifierHasher,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $query = Customer::query();

        if ($request->query('trashed') === 'only') {
            $query->onlyTrashed();
        }

        if (is_string($q = $request->query('q')) && trim($q) !== '') {
            $q = trim($q);
            $like = Paging::like($q);

            $phoneHash = null;
            $identifierHashes = [];

            try {
                $phoneHash = $this->identifierHasher->hash(
                    'customer_phone',
                    IdentifierNormalizer::customerPhone($q)
                );
            } catch (\Throwable) {
                // The query is not a valid phone identifier.
            }

            foreach ([
                'wallet_bkash',
                'wallet_nagad',
                'wallet_rocket',
            ] as $domain) {
                try {
                    $identifierHashes[] = $this->identifierHasher->hash(
                        $domain,
                        IdentifierNormalizer::wallet($q)
                    );
                } catch (\Throwable) {
                    // The query is not a valid wallet identifier.
                }
            }

            try {
                $identifierHashes[] = $this->identifierHasher->hash(
                    'bank_account',
                    IdentifierNormalizer::bankAccount($q)
                );
            } catch (\Throwable) {
                // The query is not a valid bank-account identifier.
            }

            $identifierHashes = array_values(
                array_unique($identifierHashes)
            );

            $query->where(function ($w) use (
                $like,
                $phoneHash,
                $identifierHashes
            ) {
                $w->where('name', 'like', $like)
                    ->orWhere('address', 'like', $like);

                if ($phoneHash !== null) {
                    $w->orWhere('phone_hash', $phoneHash);
                }

                if ($identifierHashes !== []) {
                    $w->orWhereExists(function ($lookup) use ($identifierHashes) {
                        $lookup->selectRaw('1')
                            ->from('customer_identifier_lookups')
                            ->whereColumn(
                                'customer_identifier_lookups.customer_id',
                                'customers.id'
                            )
                            ->whereColumn(
                                'customer_identifier_lookups.business_id',
                                'customers.business_id'
                            )
                            ->whereIn(
                                'customer_identifier_lookups.identifier_hash',
                                $identifierHashes
                            );
                    });
                }
            });
        }

        $request->query('sort') === 'name'
        ? $query->orderBy('name')->orderBy('id')
        : $query->orderByDesc('updated_at')->orderByDesc('id');

        $page = Paging::paginate($query, $request);

        return ApiResponse::success(Paging::shape($page, fn($c) => CustomerResource::make($c)->resolve($request)));
    }

    public function store(CustomerRequest $request): JsonResponse
    {
        [$customer, $created] = $this->service->create($request->validated());

        return ApiResponse::success(
            CustomerResource::make($customer)->resolve($request),
            $created ? 'Customer saved.' : 'Already saved.',
            $created ? 201 : 200,
        );
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $customer = Customer::findByUuidOrFail($id, withTrashed: true);

        return ApiResponse::success([
             ...CustomerResource::make($customer)->resolve($request),
            'summary' => $this->summary($customer),
        ]);
    }

    public function update(CustomerRequest $request, string $id): JsonResponse
    {
        $customer = Customer::findByUuidOrFail($id);

        EditGuard::assertFresh($customer, $request->input('base_updated_at'), fn($c) => CustomerResource::make($c)->resolve($request));

        $customer = $this->service->update($customer, $request->safe()->except('base_updated_at'));

        return ApiResponse::success(CustomerResource::make($customer)->resolve($request), 'Customer updated.');
    }

    public function destroy(string $id): JsonResponse
    {
        $this->service->delete(Customer::findByUuidOrFail($id));

        return ApiResponse::success(null, 'Customer deleted.');
    }

    public function restore(Request $request, string $id): JsonResponse
    {
        $customer = $this->service->restore(Customer::findByUuidOrFail($id, withTrashed: true));

        return ApiResponse::success(CustomerResource::make($customer)->resolve($request), 'Customer restored.');
    }

    /** Totals of the customer's active transactions, per currency (amounts in different currencies are never added). */
    private function summary(Customer $customer): array
    {
        $rows = ManagerTransaction::query()
            ->where('customer_id', $customer->id)
            ->selectRaw('currency, COUNT(*) AS count, SUM(amount) AS amount, SUM(commission) AS commission,
                SUM(CASE WHEN status <> ? THEN obligation_bdt ELSE 0 END) AS obligation_bdt, SUM(paid_bdt) AS paid_bdt,
                SUM(CASE WHEN status <> ? THEN obligation_bdt - paid_bdt ELSE 0 END) AS owed_bdt, MAX(occurred_at) AS last_at', ['currency_pending', 'currency_pending'])
            ->groupBy('currency')
            ->orderBy('currency')
            ->get();

        $last = $rows->max('last_at');

        return [
            'transactions'        => (int) $rows->sum('count'),
            'last_transaction_at' => $last ? BusinessClock::utcIso(new \DateTimeImmutable($last, new \DateTimeZone('UTC'))) : null,
            'by_currency'         => $rows->map(fn($r) => [
                'currency'       => $r->currency,
                'count'          => (int) $r->count,
                'amount'         => Dec::money($r->amount),
                'commission'     => Dec::money($r->commission),
                'obligation_bdt' => Dec::money($r->obligation_bdt),
                'paid_bdt'       => Dec::money($r->paid_bdt),
                'owed_bdt'       => Dec::money($r->owed_bdt),
            ])->values()->all(),
        ];
    }
}
