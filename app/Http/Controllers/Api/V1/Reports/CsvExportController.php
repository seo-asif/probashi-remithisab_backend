<?php

namespace App\Http\Controllers\Api\V1\Reports;

use App\Http\Controllers\Controller;
use App\Services\Account\AppSettingsService;
use App\Services\Reports\CsvExportService;
use App\Support\Plans\PlanAllowanceService;
use App\Support\Reports\ReportPeriod;
use App\Support\Tenancy\CurrentBusiness;
use App\Support\Time\BusinessClock;
use App\Support\Validation\Reject;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** CSV is an authenticated, Unlimited-only export; ordinary report JSON remains unchanged. */
final class CsvExportController extends Controller
{
    public function __construct(
        private CurrentBusiness $current,
        private PlanAllowanceService $plans,
        private AppSettingsService $settings,
        private CsvExportService $exports,
    ) {
    }

    public function sender(Request $request): StreamedResponse
    {
        return $this->download($request, 'sender');
    }

    public function manager(Request $request): StreamedResponse
    {
        return $this->download($request, 'manager');
    }

    private function download(Request $request, string $mode): StreamedResponse
    {
        $business = $this->current->get();
        $user = $request->user();
        $userId = (int) $user->getKey();

        // Check the current, effective subscription server-side on every download.
        abort_unless(
            $this->plans->snapshot($business, $userId)['entitlements']['csv_export'],
            403,
            'CSV export requires an active Unlimited plan.',
        );

        $datasets = $mode === 'sender'
            ? ['all', 'remittances', 'money_received']
            : ['transactions', 'remittances'];

        $data = $request->validate([
            'dataset' => ['sometimes', 'string', Rule::in($datasets)],
            'period' => ['sometimes', 'string', Rule::in(['all', 'this_month', 'last_month', 'this_year', 'custom'])],
            'from' => ['sometimes', 'required', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'required', 'date_format:Y-m-d'],
        ]);

        $hasFrom = array_key_exists('from', $data);
        $hasTo = array_key_exists('to', $data);
        if ($hasFrom !== $hasTo) {
            Reject::fail(['from' => ['date_range_incomplete', 'Both From and To dates are required.']]);
        }
        if (($data['period'] ?? null) === 'custom' && !$hasFrom) {
            Reject::fail(['from' => ['date_range_incomplete', 'Custom export requires From and To dates.']]);
        }
        if ($hasFrom && isset($data['period']) && $data['period'] !== 'custom') {
            Reject::fail(['period' => ['period_conflict', 'Choose either a named period or a custom date range.']]);
        }

        $period = ReportPeriod::fromQuery($data, BusinessClock::for($business), default: 'this_month');
        $dataset = $data['dataset'] ?? ($mode === 'sender' ? 'all' : 'transactions');
        $language = $this->settings->snapshot($user, $business)['language'] === 'bn' ? 'bn' : 'en';

        return $this->exports->download($mode, $dataset, $period, $language, $business, $userId);
    }
}
