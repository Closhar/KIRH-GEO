<?php

namespace Tests\Feature;

use App\Modules\Billing\Domain\BillingMath;
use App\Modules\Billing\Infrastructure\SandboxBillingProvider;
use App\Modules\Billing\Infrastructure\YooKassaBillingProvider;
use App\Modules\Partners\Domain\CommissionMath;
use App\Support\ApiException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BillingDomainTest extends TestCase
{
    public function test_month_and_year_do_not_overflow_calendar_end(): void
    {
        $this->assertSame('2026-02-28', BillingMath::periodEnd(CarbonImmutable::parse('2026-01-31'), 'month')->toDateString());
        $this->assertSame('2025-02-28', BillingMath::periodEnd(CarbonImmutable::parse('2024-02-29'), 'year')->toDateString());
        $this->assertSame(750, BillingMath::discountedAmount(1000, 2500, null));
        $this->assertSame(0, BillingMath::discountedAmount(1000, null, 1500));
    }

    public function test_commission_partial_refunds_round_cumulatively(): void
    {
        $this->assertSame(333, CommissionMath::calculate(1000, 3333, null));
        $first = CommissionMath::reversal(333, 1000, 333, 0);
        $second = CommissionMath::reversal(333, 1000, 666, $first);
        $last = CommissionMath::reversal(333, 1000, 1000, $first + $second);
        $this->assertSame(333, $first + $second + $last);
        $this->assertSame(0, CommissionMath::calculate(0, null, 100));
    }

    public function test_sandbox_authentication_requires_fresh_signed_raw_body(): void
    {
        config(['billing.sandbox_enabled' => true, 'billing.sandbox_webhook_secret' => str_repeat('k', 32)]);
        $body = json_encode(['event_id' => 'e', 'payment_id' => 'p', 'status' => 'succeeded', 'amount_minor' => 100, 'currency' => 'RUB']);
        $time = (string) time();
        $headers = ['x-billing-timestamp' => [$time], 'x-billing-signature' => [hash_hmac('sha256', $time.'.'.$body, str_repeat('k', 32))]];
        $this->assertSame(100, app(SandboxBillingProvider::class)->verifyWebhook($body, $headers)->amountMinor);
        $this->expectException(ApiException::class);
        app(SandboxBillingProvider::class)->verifyWebhook($body.' ', $headers);
    }

    public function test_yookassa_uses_authoritative_api_values_instead_of_incoming_amount(): void
    {
        config(['billing.yookassa.enabled' => true, 'billing.yookassa.shop_id' => 'shop', 'billing.yookassa.secret' => 'secret']);
        Http::preventStrayRequests();
        Http::fake(['https://api.yookassa.ru/v3/payments/payment-1' => Http::response(['id' => 'payment-1', 'status' => 'succeeded', 'amount' => ['value' => '10.50', 'currency' => 'RUB']])]);
        $event = app(YooKassaBillingProvider::class)->verifyWebhook(json_encode(['event' => 'payment.succeeded', 'object' => ['id' => 'payment-1', 'amount' => ['value' => '999999.00']]]), []);
        $this->assertSame(1050, $event->amountMinor);
        Http::assertSentCount(1);
    }

    public function test_yookassa_recurring_request_has_saved_method_and_stable_key(): void
    {
        config(['billing.yookassa.enabled' => true, 'billing.yookassa.shop_id' => 'shop', 'billing.yookassa.secret' => 'secret']);
        Http::preventStrayRequests();
        Http::fake(['https://api.yookassa.ru/v3/payments' => Http::response(['id' => 'renewed-payment'])]);
        $result = app(YooKassaBillingProvider::class)->chargeSavedMethod('stable-key', 'saved-method', 12345, 'RUB');
        $this->assertSame('renewed-payment', $result['external_id']);
        Http::assertSent(fn ($request) => $request->hasHeader('Idempotence-Key', 'stable-key') && $request['payment_method_id'] === 'saved-method' && $request['amount']['value'] === '123.45' && $request['capture'] === true);
    }
}
