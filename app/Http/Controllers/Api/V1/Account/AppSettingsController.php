<?php

namespace App\Http\Controllers\Api\V1\Account;

use App\Http\Controllers\Controller;
use App\Http\Requests\Account\UpdateAppSettingsRequest;
use App\Http\Responses\ApiResponse;
use App\Services\Account\AppSettingsService;
use App\Support\Tenancy\CurrentBusiness;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AppSettingsController extends Controller
{
    public function show(Request $request, CurrentBusiness $current, AppSettingsService $settings): JsonResponse
    {
        return ApiResponse::success($settings->snapshot($request->user(), $current->get()));
    }

    public function update(UpdateAppSettingsRequest $request, CurrentBusiness $current, AppSettingsService $settings): JsonResponse
    {
        return ApiResponse::success(
            $settings->save($request->user(), $request->validated(), $current->get()),
            'App settings saved.',
        );
    }
}
