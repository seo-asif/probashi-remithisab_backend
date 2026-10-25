<?php

namespace App\Http\Controllers\Api\V1\Reports;

use App\Http\Controllers\Controller;
use App\Services\Reports\PdfExportService;
use App\Support\Plans\PlanAllowanceService;
use App\Support\Reports\ReportPeriod;
use App\Support\Tenancy\CurrentBusiness;
use App\Support\Time\BusinessClock;
use App\Support\Validation\Reject;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

final class PdfExportController extends Controller
{
    public function __construct(
        private CurrentBusiness $current,
        private PlanAllowanceService $plans,
        private PdfExportService $exports,
    ) {}

    public function sender(Request $request): Response
    {
        return $this->download($request, 'sender');
    }

    public function manager(Request $request): Response
    {
        return $this->download($request, 'manager');
    }

    public function student(Request $request): Response
    {
        return $this->download($request, 'student');
    }

    private function download(Request $request, string $mode): Response
    {
        $business = $this->current->get();
        $userId = (int) $request->user()->getKey();
        $entitlements = $this->plans->snapshot($business, $userId)['entitlements'];

        abort_unless($entitlements['monthly_pdf'] ?? false, 403);

        $data = $request->validate([
            'period' => ['sometimes', 'string', Rule::in(ReportPeriod::PERIODS)],
            'from' => ['sometimes', 'required', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'required', 'date_format:Y-m-d'],
        ]);

        $hasFrom = array_key_exists('from', $data);
        $hasTo = array_key_exists('to', $data);
        if ($hasFrom !== $hasTo) {
            Reject::fail(['from' => ['date_range_incomplete', 'Both dates are required.']]);
        }
        if (($data['period'] ?? null) === 'custom' && !$hasFrom) {
            Reject::fail(['from' => ['date_range_incomplete', 'Custom dates are required.']]);
        }
        if ($hasFrom && isset($data['period']) && $data['period'] !== 'custom') {
            Reject::fail(['period' => ['period_conflict', 'Choose a named period or custom dates.']]);
        }

        $period = ReportPeriod::fromQuery($data, BusinessClock::for($business), default: 'this_month');
        if ($period->period !== 'this_month') {
            abort_unless($entitlements['custom_date_range'] ?? false, 403, 'This PDF period requires Unlimited.');
        }

        return $this->exports->download($mode, $period, $business, $userId);
    }
}
