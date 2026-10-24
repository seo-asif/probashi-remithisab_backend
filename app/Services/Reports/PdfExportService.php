<?php

namespace App\Services\Reports;

use App\Models\Business;
use App\Support\Reports\ManagerReport;
use App\Support\Reports\ReportPeriod;
use App\Support\Reports\SenderReport;
use App\Support\Tenancy\CurrentBusiness;
use App\Support\Time\BusinessClock;
use Barryvdh\DomPDF\Facade\Pdf;
use Symfony\Component\HttpFoundation\Response;

final class PdfExportService
{
    public function download(string $mode, ReportPeriod $period, Business $business, int $userId): Response
    {
        $current = app(CurrentBusiness::class);
        $generate = function () use ($mode, $period, $business): Response {
            $clock = BusinessClock::for($business);
            $summary = $mode === 'sender'
            ? (new SenderReport($clock))->totals($period)
            : (new ManagerReport($clock))->totals($period);

            $pdf = Pdf::loadView('reports.export', [
                'mode'    => $mode,
                'summary' => $summary,
                'period'  => $period,
            ])->setPaper('a4', 'portrait');

            $response = $pdf->download(
                "probashi-{$mode}-{$period->period}.pdf"
            );

            $response->headers->set(
                'Cache-Control',
                'private, no-store, no-cache, must-revalidate'
            );

            $response->headers->set('Pragma', 'no-cache');
            $response->headers->set('X-Content-Type-Options', 'nosniff');

            return $response;

        };

        // Render before the middleware clears CurrentBusiness, and always
        // restore the trusted user scope for sender reports.
        return $mode === 'sender'
        ? $current->runAsUser($business, $userId, $generate)
        : $current->runAs($business, $generate);
    }
}
