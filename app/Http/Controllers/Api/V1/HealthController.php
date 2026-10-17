<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Support\Time\BusinessClock;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class HealthController extends Controller
{
    /** Public. Shows nothing about the server except whether it can answer. */
    public function __invoke(): JsonResponse
    {
        try {
            DB::select('select 1');
        } catch (\Throwable) {
            return ApiResponse::error('SERVICE_UNAVAILABLE', 'Service temporarily unavailable.', 503);
        }

        return ApiResponse::success([
            'status'      => 'ok',
            'api_version' => config('remit.api_version'),
            'time_utc'    => BusinessClock::utcIso(new \DateTimeImmutable('now')),
        ]);
    }
}
