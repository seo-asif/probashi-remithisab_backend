<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\BusinessResource;
use App\Http\Resources\UserResource;
use App\Http\Responses\ApiResponse;
use App\Services\Account\AppSettingsService;
use App\Support\Plans\PlanAllowanceService;
use App\Support\Tenancy\CurrentBusiness;
use App\Support\Time\BusinessClock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MeController extends Controller
{
    public function __invoke(
        Request $request,
        CurrentBusiness $current,
        PlanAllowanceService $plans,
    ): JsonResponse {
        $business = $current->get();
        $clock = BusinessClock::for($business);
        $now = new \DateTimeImmutable('now');

        return ApiResponse::success([
            'user'       => UserResource::make($request->user())->resolve($request),
            'app_settings' => app(AppSettingsService::class)->snapshot($request->user(), $business),
            'business'   => BusinessResource::make($business)->resolve($request),

            'membership' => [
                'role' => $current->membership()->role->value,
            ],

            'plan'       => $plans->snapshot(
                $business,
                (int) $request->user()->id,
                $now,
            ),

            'time'       => [
                'server_utc'        => BusinessClock::utcIso($now),
                'business_local'    => $clock->localIso($now),
                'business_timezone' => $clock->timezone(),
            ],
        ]);
    }
}
