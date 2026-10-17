<?php

namespace App\Http\Controllers\Api\V1\Manager;

use App\Http\Controllers\Controller;
use App\Http\Requests\Manager\RemittanceRequest;
use App\Http\Resources\Manager\RemittanceResource;
use App\Http\Responses\ApiResponse;
use App\Models\ManagerRemittance;
use App\Services\Manager\RemittanceService;
use App\Support\Api\EditGuard;
use App\Support\Api\Paging;
use App\Support\Manager\ManagerOptions;
use App\Support\Reports\ReportPeriod;
use App\Support\Tenancy\CurrentBusiness;
use App\Support\Time\BusinessClock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** /manager-remittances: list (?method= ?currency= + period, ?trashed=only), create, show, change, delete, restore. */
class RemittanceController extends Controller
{
    public function __construct(private RemittanceService $service, private CurrentBusiness $current)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $query = ManagerRemittance::query();

        if ($request->query('trashed') === 'only') {
            $query->onlyTrashed();
        }
        ReportPeriod::fromQuery($request->query(), BusinessClock::for($this->current->get()))->apply($query, 'occurred_at');
        if ($request->filled('method')) {
            $query->where('method', ManagerOptions::canonical((string) $request->query('method'), ManagerOptions::REMIT_METHODS));
        }
        if ($request->filled('currency')) {
            $query->where('currency', strtoupper((string) $request->query('currency')));
        }

        $page = Paging::paginate($query->orderByDesc('occurred_at')->orderByDesc('id'), $request);

        return ApiResponse::success(Paging::shape($page, fn ($r) => RemittanceResource::make($r)->resolve($request)));
    }

    public function store(RemittanceRequest $request): JsonResponse
    {
        [$r, $created] = $this->service->create($request->validated());

        return ApiResponse::success(RemittanceResource::make($r)->resolve($request), $created ? 'Remittance saved.' : 'Already saved.', $created ? 201 : 200);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return ApiResponse::success(RemittanceResource::make(ManagerRemittance::findByUuidOrFail($id, withTrashed: true))->resolve($request));
    }

    public function update(RemittanceRequest $request, string $id): JsonResponse
    {
        $r = ManagerRemittance::findByUuidOrFail($id);
        EditGuard::assertFresh($r, $request->input('base_updated_at'), fn ($x) => RemittanceResource::make($x)->resolve($request));

        return ApiResponse::success(RemittanceResource::make($this->service->update($r, $request->safe()->except(['base_updated_at', 'id'])))->resolve($request), 'Remittance updated.');
    }

    public function destroy(string $id): JsonResponse
    {
        $this->service->delete(ManagerRemittance::findByUuidOrFail($id));

        return ApiResponse::success(null, 'Remittance deleted.');
    }

    public function restore(Request $request, string $id): JsonResponse
    {
        return ApiResponse::success(RemittanceResource::make($this->service->restore(ManagerRemittance::findByUuidOrFail($id, withTrashed: true)))->resolve($request), 'Remittance restored.');
    }
}
