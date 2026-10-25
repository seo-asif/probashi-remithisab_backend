<?php

namespace App\Services\Reports;

use App\Models\Business;
use App\Services\Student\StudentService;
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
            $summary = match ($mode) {
                'sender'  => (new SenderReport($clock))->totals($period),
                'manager' => (new ManagerReport($clock))->totals($period),
                'student' => [
                    'timezone' => $clock->timezone(),
                    ...(app(StudentService::class)->summary(
                        $period->fromDate ?? '1900-01-01',
                        $period->toDate ?? $clock->toLocal(now())->format('Y-m-d'),
                    )),
                ],
                default   => throw new \InvalidArgumentException('Unsupported PDF report mode.'),
            };

            $pdf = Pdf::loadView('reports.export', [
                'mode'    => $mode,
                'summary' => $summary,
                'period'  => $period,
            ])->setPaper('a4', 'portrait');

            // Render the bytes before declaring the request successful.
            // A Content-Type of application/pdf alone does not prove that
            // DomPDF returned a valid PDF document.
            $bytes = $pdf->output();
            if (!is_string($bytes)
                || !str_starts_with($bytes, '%PDF-')
                || !str_contains(substr($bytes, -2048), '%%EOF')) {
                throw new \RuntimeException('PDF rendering failed integrity validation.');
            }

            $filename = "probashi-{$mode}-{$period->period}.pdf";

            return response($bytes, 200, [
                'Content-Type'           => 'application/pdf',
                'Content-Disposition'    => 'attachment; filename="' . $filename . '"',
                'Cache-Control'          => 'private, no-store, no-cache, must-revalidate',
                'Pragma'                 => 'no-cache',
                'X-Content-Type-Options' => 'nosniff',
            ]);

        };

        // Render before the middleware clears CurrentBusiness, and always
        // restore the trusted user scope for sender reports.
        return in_array($mode, ['sender', 'student'], true)
        ? $current->runAsUser($business, $userId, $generate)
        : $current->runAs($business, $generate);
    }
}
