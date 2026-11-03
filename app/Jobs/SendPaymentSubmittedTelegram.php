<?php

namespace App\Jobs;

use App\Models\PlanPaymentRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

final class SendPaymentSubmittedTelegram implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly int $paymentId)
    {
        $this->onQueue('notifications');
        $this->afterCommit();
    }

    public function handle(): void
    {
        $token = (string) config('services.telegram_admin.bot_token', '');
        $chatId = (string) config('services.telegram_admin.chat_id', '');

        if ($token === '' || $chatId === '') {
            return;
        }

        $payment = PlanPaymentRequest::query()->find($this->paymentId);

        if ($payment === null) {
            return;
        }

        $product = match ($payment->product_type->value) {
            'sender_plus' => 'Sender Plus',
            'manager_pro' => 'Manager Pro',
            default => null,
        };

        if ($product === null) {
            return;
        }

        $url = rtrim((string) config('app.url'), '/')
            . '/admin/payments/' . $payment->id;

        $message = implode("\n", [
            'New payment request',
            'Request #' . $payment->id,
            'Product: ' . $product,
            'Duration: ' . $payment->package_months . ' months',
            'Expected amount: BDT ' . number_format($payment->expected_amount_bdt),
            'Review: ' . $url,
        ]);

        try {
            $response = Http::asForm()
                ->connectTimeout(3)
                ->timeout(7)
                ->post(
                    'https://api.telegram.org/bot' . $token . '/sendMessage',
                    [
                        'chat_id' => $chatId,
                        'text' => $message,
                        'disable_web_page_preview' => true,
                    ],
                );

            if (!$response->successful()) {
                Log::warning('Admin Telegram notification failed', [
                    'payment_id' => $payment->id,
                    'http_status' => $response->status(),
                ]);
            }
        } catch (Throwable $exception) {
            // Do not log exception messages: they may contain the bot token.
            Log::warning('Admin Telegram notification could not be delivered', [
                'payment_id' => $payment->id,
                'exception_type' => $exception::class,
            ]);
        }
    }
}
