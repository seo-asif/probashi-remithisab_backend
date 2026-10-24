<?php

namespace App\Services\Reports;

use App\Models\Business;
use App\Models\ManagerRemittance;
use App\Models\ManagerTransaction;
use App\Models\SenderRemittance;
use App\Models\StudentMoneyIn;
use App\Support\Reports\ReportPeriod;
use App\Support\Tenancy\CurrentBusiness;
use App\Support\Time\BusinessClock;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Streams a read-only CSV with no cross-currency arithmetic or sensitive destination details. */
final class CsvExportService
{
    private const HEADINGS = [
        'sender' => [
            'en' => ['Type', 'Date', 'Name', 'Currency', 'Amount', 'Exchange Rate', 'Bank Charge', 'BDT Amount', 'Method', 'Status', 'Note'],
            'bn' => ['ধরন', 'তারিখ', 'নাম', 'মুদ্রা', 'পরিমাণ', 'বিনিময় হার', 'ব্যাংক চার্জ', 'টাকার পরিমাণ (BDT)', 'মাধ্যম', 'অবস্থা', 'নোট'],
        ],
        'transactions' => [
            'en' => ['Date', 'Customer', 'Currency', 'Amount', 'Exchange Rate', 'Commission', 'BDT Obligation', 'BDT Paid', 'BDT Outstanding', 'Status', 'Receive Method'],
            'bn' => ['তারিখ', 'গ্রাহক', 'মুদ্রা', 'পরিমাণ', 'বিনিময় হার', 'কমিশন', 'পাওনা (BDT)', 'পরিশোধ (BDT)', 'বাকি (BDT)', 'অবস্থা', 'গ্রহণের মাধ্যম'],
        ],
        'remittances' => [
            'en' => ['Date', 'Currency', 'Amount', 'Exchange Rate', 'Bank Charge', 'Remitted BDT', 'Method', 'Note'],
            'bn' => ['তারিখ', 'মুদ্রা', 'পরিমাণ', 'বিনিময় হার', 'ব্যাংক চার্জ', 'পাঠানো টাকা (BDT)', 'মাধ্যম', 'নোট'],
        ],
    ];

    public function download(
        string $mode,
        string $dataset,
        ReportPeriod $period,
        string $language,
        Business $business,
        int $userId,
    ): StreamedResponse {
        $filename = "probashi-{$mode}-{$dataset}-{$period->period}.csv";
        $current = app(CurrentBusiness::class);

        // The business.context middleware clears CurrentBusiness when it returns
        // the response. A StreamedResponse callback runs LATER, so restore the
        // trusted business/owner scope inside the callback, never from query input.
        return response()->streamDownload(
            function () use ($current, $mode, $dataset, $period, $language, $business, $userId): void {
                $write = function () use ($mode, $dataset, $period, $language, $business): void {
                    $stream = fopen('php://output', 'wb');
                    if ($stream === false) {
                        throw new \RuntimeException('Could not open CSV output stream.');
                    }
                    try {
                        fwrite($stream, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel and Bangla text.
                        $kind = $mode === 'sender' ? 'sender' : $dataset;
                        $this->writeRow($stream, self::HEADINGS[$kind][$language], false);
                        $clock = BusinessClock::for($business);

                        if ($mode === 'sender') {
                            $this->writeSender($stream, $dataset, $period, $language, $clock);
                        } elseif ($dataset === 'transactions') {
                            $this->writeTransactions($stream, $period, $clock);
                        } else {
                            $this->writeManagerRemittances($stream, $period, $clock);
                        }
                    } finally {
                        fclose($stream);
                    }
                };

                if ($mode === 'sender') {
                    $current->runAsUser($business, $userId, $write);
                } else {
                    $current->runAs($business, $write);
                }
            },
            $filename,
            [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Cache-Control' => 'private, no-store, no-cache, must-revalidate',
                'Pragma' => 'no-cache',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }

    private function writeSender($stream, string $dataset, ReportPeriod $period, string $language, BusinessClock $clock): void
    {
        if ($dataset !== 'money_received') {
            $query = SenderRemittance::query();
            $period->apply($query, 'occurred_at');
            foreach ($query->orderByDesc('occurred_at')->orderByDesc('id')->cursor() as $r) {
                $this->writeRow($stream, [
                    $language === 'bn' ? 'পাঠানো টাকা' : 'Remittance',
                    $clock->toLocal($r->occurred_at)->format('Y-m-d'),
                    $r->recipient_name,
                    $r->currency,
                    $r->amount,
                    $r->rate,
                    $r->charge,
                    $r->family_bdt,
                    $r->method,
                    $r->received ? ($language === 'bn' ? 'পেয়েছেন' : 'Received') : ($language === 'bn' ? 'পাননি' : 'Not received'),
                    $r->notes,
                ]);
            }
        }

        if ($dataset !== 'remittances') {
            $query = StudentMoneyIn::query();
            if ($period->fromDate !== null) {
                $query->where('received_on', '>=', $period->fromDate);
            }
            if ($period->toDate !== null) {
                $query->where('received_on', '<=', $period->toDate);
            }
            foreach ($query->orderByDesc('received_on')->orderByDesc('id')->cursor() as $r) {
                $this->writeRow($stream, [
                    $language === 'bn' ? 'বাড়ি থেকে পেয়েছি' : 'Money received',
                    $r->received_on->format('Y-m-d'),
                    $r->from_name,
                    'BDT',
                    $r->amount_bdt,
                    $r->rate_bdt_per_currency,
                    '',
                    $r->amount_bdt,
                    $r->method,
                    '',
                    $r->note,
                ]);
            }
        }
    }

    private function writeTransactions($stream, ReportPeriod $period, BusinessClock $clock): void
    {
        $query = ManagerTransaction::query();
        $period->apply($query, 'occurred_at');
        foreach ($query->orderByDesc('occurred_at')->orderByDesc('id')->cursor() as $r) {
            $this->writeRow($stream, [
                $clock->toLocal($r->occurred_at)->format('Y-m-d'),
                $r->customer_name,
                $r->currency,
                $r->amount,
                $r->rate,
                $r->commission,
                $r->obligation_bdt,
                $r->paid_bdt,
                bcsub((string) $r->obligation_bdt, (string) $r->paid_bdt, 2),
                $r->status,
                $r->receive_method,
            ]);
        }
    }

    private function writeManagerRemittances($stream, ReportPeriod $period, BusinessClock $clock): void
    {
        $query = ManagerRemittance::query();
        $period->apply($query, 'occurred_at');
        foreach ($query->orderByDesc('occurred_at')->orderByDesc('id')->cursor() as $r) {
            $this->writeRow($stream, [
                $clock->toLocal($r->occurred_at)->format('Y-m-d'),
                $r->currency,
                $r->amount,
                $r->rate,
                $r->charge,
                $r->remit_bdt,
                $r->method,
                $r->notes,
            ]);
        }
    }

    /** @param array<int, mixed> $cells */
    private function writeRow($stream, array $cells, bool $escapeFormulas = true): void
    {
        $safe = array_map(function (mixed $cell) use ($escapeFormulas): string {
            $value = $cell === null ? '' : (string) $cell;
            // Prefix text that spreadsheet programs might evaluate as a formula.
            // Decimal amounts are trusted numeric database columns, so negative
            // amounts remain numbers rather than being converted to text.
            if ($escapeFormulas && preg_match('/\A[\x00-\x20]*[=+\-@]/', $value)
                && !preg_match('/\A-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?\z/D', $value)) {
                $value = "'" . $value;
            }
            return $value;
        }, $cells);

        // Explicit empty escape character avoids PHP's non-RFC backslash escaping.
        fputcsv($stream, $safe, ',', '"', '', "\r\n");
    }
}
