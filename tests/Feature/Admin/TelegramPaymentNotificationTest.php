<?php

namespace Tests\Feature\Admin;

use App\Enums\PlanPaymentProduct;
use App\Jobs\SendPaymentSubmittedTelegram;
use App\Models\Business;
use App\Models\PlanPaymentRequest;
use App\Models\User;
use App\Support\Plans\IndependentPlanPackages;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class TelegramPaymentNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_telegram_message_contains_only_review_information(): void
    {
        config()->set('services.telegram_admin.bot_token', 'test-bot-token');
        config()->set('services.telegram_admin.chat_id', '12345');
        config()->set('app.url', 'http://127.0.0.1:8000');
        Http::preventStrayRequests();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        $business = Business::factory()->create();
        /** @var User $sender */
        $sender = User::factory()->create();
        $payment = PlanPaymentRequest::create([
            'business_id' => $business->id,
            'submitted_by_user_id' => $sender->id,
            'beneficiary_user_id' => $sender->id,
            'product_type' => PlanPaymentProduct::SenderPlus,
            'package_months' => 3,
            'expected_amount_bdt' => IndependentPlanPackages::priceBdt(PlanPaymentProduct::SenderPlus, 3),
            'trx_id' => 'TEST123456',
            'trx_id_hash' => hash('sha256', 'test-telegram-payment'),
            'payer_number' => '01712345678',
        ]);

        (new SendPaymentSubmittedTelegram($payment->id))->handle();

        Http::assertSent(function ($request) use ($payment): bool {
            $text = $request['text'];
            return str_contains($text, 'Request #' . $payment->id)
                && str_contains($text, 'Sender Plus')
                && str_contains($text, 'BDT 297')
                && str_contains($text, '/admin/payments/' . $payment->id)
                && !str_contains($text, '01712345678')
                && !str_contains($text, 'TEST123456');
        });
        Http::assertSentCount(1);
    }

    public function test_telegram_is_disabled_without_configuration(): void
    {
        config()->set('services.telegram_admin.bot_token', '');
        config()->set('services.telegram_admin.chat_id', '');
        Http::preventStrayRequests();
        Http::fake();
        (new SendPaymentSubmittedTelegram(100000))->handle();
        Http::assertNothingSent();
    }
}
