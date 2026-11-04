<?php

namespace App\Http\Controllers\Api\V1\Activity;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Records at most one UTC active day per authenticated user. */
final class DailyActiveController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $day = now('UTC')->toDateString();
        DB::table('user_active_days')->insertOrIgnore([
            'user_id' => $request->user()->id,
            'activity_date' => $day,
            'created_at' => now('UTC'),
        ]);

        return ApiResponse::success(['activity_date' => $day]);
    }
}
