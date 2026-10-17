<?php

namespace Tests\Support;

use App\Http\Responses\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/** Throw-away routes used only by tests. Controllers must look records up by id, exactly like these. */
final class ProbeRoutes
{
    public static function register(): void
    {
        Route::middleware(['api', 'auth:sanctum', 'business.context'])->prefix('api/v1/_probe')->group(function () {
            Route::get('/', fn () => ApiResponse::success(TenancyProbe::query()->orderBy('id')->pluck('label')->all()));

            Route::post('/', function (Request $request) {
                $probe = TenancyProbe::create(['label' => $request->input('label', 'x')]); // business_id is never taken from input

                return ApiResponse::success(['id' => $probe->id], 'Created', 201);
            });

            Route::get('/{id}', fn (int $id) => ApiResponse::success(['label' => TenancyProbe::query()->findOrFail($id)->label]));

            Route::patch('/{id}', function (Request $request, int $id) {
                TenancyProbe::query()->findOrFail($id)->update(['label' => $request->input('label')]);

                return ApiResponse::success();
            });

            Route::delete('/{id}', function (int $id) {
                TenancyProbe::query()->findOrFail($id)->delete();

                return ApiResponse::success();
            });
        });

        Route::middleware(['api', 'auth:sanctum', 'business.context'])->prefix('api/v1/_owner_probe')->group(function () {
            Route::get('/', fn () => ApiResponse::success(OwnerProbe::query()->orderBy('id')->pluck('label')->all()));

            Route::post('/', function (Request $request) {
                // owner_user_id and business_id are never taken from input
                $probe = OwnerProbe::create(['label' => $request->input('label', 'x')]);

                return ApiResponse::success(['id' => $probe->id], 'Created', 201);
            });

            Route::get('/{id}', fn (int $id) => ApiResponse::success(['label' => OwnerProbe::query()->findOrFail($id)->label]));

            Route::patch('/{id}', function (Request $request, int $id) {
                OwnerProbe::query()->findOrFail($id)->update(['label' => $request->input('label')]);

                return ApiResponse::success();
            });

            Route::delete('/{id}', function (int $id) {
                OwnerProbe::query()->findOrFail($id)->delete();

                return ApiResponse::success();
            });
        });

        Route::middleware('api')->prefix('api/v1/_probe_public')->group(function () {
            Route::post('/validate', fn (Request $request) => $request->validate(['name' => 'required']));
            Route::get('/boom', fn () => throw new \RuntimeException('secret database password'));
        });
    }
}
