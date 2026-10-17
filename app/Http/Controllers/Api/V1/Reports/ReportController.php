<?php

namespace App\Http\Controllers\Api\V1\Reports;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Support\Reports\ManagerReport;
use App\Support\Reports\ReportPeriod;
use App\Support\Reports\SenderReport;
use App\Support\Tenancy\CurrentBusiness;
use App\Support\Time\BusinessClock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /reports/manager            per currency, with estimated profit (?period=all|this_month|last_month|this_year or ?from=&to=)
 * GET /reports/manager/monthly    the same, month by month (for the PDF)
 * GET /reports/manager/home       the Home tiles (this month; "still to pay" over all months; no profit)
 * GET /reports/sender             the logged-in user's Sender totals
 * GET /reports/sender/monthly
 */
class ReportController extends Controller
{
    public function __construct(private CurrentBusiness $current)
    {
    }

    public function manager(Request $request): JsonResponse
    {
        $clock = $this->clock();

        return ApiResponse::success((new ManagerReport($clock))->totals(ReportPeriod::fromQuery($request->query(), $clock)));
    }

    public function managerMonthly(Request $request): JsonResponse
    {
        $clock = $this->clock();

        return ApiResponse::success((new ManagerReport($clock))->monthly(ReportPeriod::fromQuery($request->query(), $clock)));
    }

    public function managerHome(): JsonResponse
    {
        return ApiResponse::success((new ManagerReport($this->clock()))->home(new \DateTimeImmutable('now')));
    }

    public function sender(Request $request): JsonResponse
    {
        $clock = $this->clock();

        return ApiResponse::success((new SenderReport($clock))->totals(ReportPeriod::fromQuery($request->query(), $clock)));
    }

    public function senderMonthly(Request $request): JsonResponse
    {
        $clock = $this->clock();

        return ApiResponse::success((new SenderReport($clock))->monthly(ReportPeriod::fromQuery($request->query(), $clock)));
    }

    private function clock(): BusinessClock
    {
        return BusinessClock::for($this->current->get());
    }
}
