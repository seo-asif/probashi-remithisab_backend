<?php

namespace App\Http\Controllers\Api\V1\Plan;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Support\Plans\PlanAllowanceService;
use App\Support\Plans\TrialService;
use App\Support\Tenancy\CurrentBusiness;
use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LogicException;

final class TrialController extends Controller
{
    public function __invoke(
        Request $request,
        CurrentBusiness $current,
        TrialService $trials,
        PlanAllowanceService $plans,
    ): JsonResponse {
        $business = $current->get();
        $membership = $current->membership();

        if ($business === null || $membership === null) {
            throw new LogicException(
                'Trial activation requires an active business context.'
            );
        }

        $trials->activate(
            $business,
            $membership,
        );

        $now = new DateTimeImmutable('now');

        return ApiResponse::success(
            [
                'plan' => $plans->snapshot(
                    $business,
                    (int) $request->user()->id,
                    $now,
                ),
            ],
            'Your 7-day Unlimited trial has started.',
        );
    }
}