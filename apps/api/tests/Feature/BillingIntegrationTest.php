<?php

namespace Tests\Feature;

use App\Modules\Access\AccessSeeder;
use App\Modules\Access\EntitlementService;
use App\Modules\Billing\Application\BillingService;
use App\Modules\Partners\Application\PartnerService;
use App\Modules\Workspaces\WorkspaceService;
use App\Support\ApiException;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class BillingIntegrationTest extends TestCase
{
    use DatabaseTransactions;

    private function fixture(): array
    {
        $this->seed(AccessSeeder::class);
        config(['billing.sandbox_enabled' => true, 'billing.sandbox_webhook_secret' => str_repeat('s', 32)]);
        $user = (string) Str::uuid();
        DB::table('users')->insert(['id' => $user, 'name' => 'Billing user']);
        $workspace = app(WorkspaceService::class)->create($user, 'Family')['id'];
        $plan = (string) Str::uuid();
        $price = (string) Str::uuid();
        DB::table('plans')->insert(['id' => $plan, 'code' => 'test-'.Str::uuid(), 'version' => 1, 'name' => 'Test', 'status' => 'published']);
        $featureId = DB::table('features')->where('key', 'members.max')->value('id');
        DB::table('plan_features')->insert(['plan_id' => $plan, 'feature_id' => $featureId, 'value' => '42']);
        DB::table('plan_prices')->insert(['id' => $price, 'plan_id' => $plan, 'interval' => 'month', 'currency' => 'RUB', 'amount_minor' => 10000, 'provider' => 'sandbox']);

        return [$user, $workspace, $plan, $price];
    }

    private function sendCallback(string $paymentId, string $eventId, string $status = 'succeeded', int $amount = 10000, ?string $refund = null, ?string $savedMethod = null): void
    {
        $payload = ['event_id' => $eventId, 'payment_id' => $paymentId, 'status' => $status, 'amount_minor' => $amount, 'currency' => 'RUB'];
        if ($refund) {
            $payload['refund_id'] = $refund;
        }
        if ($savedMethod) {
            $payload['saved_payment_method_id'] = $savedMethod;
        }
        $raw = json_encode($payload);
        $time = (string) time();
        app(BillingService::class)->webhook('sandbox', $raw, ['x-billing-timestamp' => [$time], 'x-billing-signature' => [hash_hmac('sha256', $time.'.'.$raw, str_repeat('s', 32))]]);
    }

    public function test_checkout_replay_verified_callback_and_late_cancel_do_not_duplicate(): void
    {
        [$user, $workspace, , $price] = $this->fixture();
        $service = app(BillingService::class);
        $key = (string) Str::uuid();
        $first = $service->checkout($user, $workspace, $key, $price);
        $this->assertEquals($first, $service->checkout($user, $workspace, $key, $price));
        $this->assertSame(1, DB::table('payments')->where('workspace_id', $workspace)->count());
        $payment = DB::table('payments')->where('workspace_id', $workspace)->first();
        $this->sendCallback($payment->external_id, 'settled');
        $this->sendCallback($payment->external_id, 'settled');
        $this->sendCallback($payment->external_id, 'late-cancel', 'canceled');
        $this->assertSame('succeeded', DB::table('payments')->where('id', $payment->id)->value('status'));
        $this->assertSame(1, DB::table('invoices')->where('workspace_id', $workspace)->count());
        $this->assertSame(42, app(EntitlementService::class)->resolve($workspace, 'members.max'));
        $this->sendCallback($payment->external_id, 'refund-one', 'refunded', 10000, 'r1');
        $this->sendCallback($payment->external_id, 'refund-one', 'refunded', 10000, 'r1');
        $this->assertSame(1, DB::table('refunds')->where('payment_id', $payment->id)->count());
        $this->assertSame('canceled', DB::table('subscriptions')->where('id', $payment->subscription_id)->value('status'));
        $this->assertNotSame(42, app(EntitlementService::class)->resolve($workspace, 'members.max'));
    }

    public function test_callback_amount_mismatch_never_grants_entitlement(): void
    {
        [$user, $workspace, , $price] = $this->fixture();
        app(BillingService::class)->checkout($user, $workspace, (string) Str::uuid(), $price);
        $payment = DB::table('payments')->where('workspace_id', $workspace)->first();
        try {
            $this->sendCallback($payment->external_id, 'forged-amount', 'succeeded', 9999);
            $this->fail('Amount mismatch must fail.');
        } catch (ApiException $exception) {
            $this->assertSame('PAYMENT_MISMATCH', $exception->errorCode);
        }
        $this->assertSame('pending', DB::table('payments')->where('id', $payment->id)->value('status'));
        $this->assertNotSame(42, app(EntitlementService::class)->resolve($workspace, 'members.max'));
    }

    public function test_trial_and_free_access_promo_have_atomic_replay_limits(): void
    {
        [$user, $workspace, $plan] = $this->fixture();
        config(['billing.trial_plan_id' => $plan]);
        $service = app(BillingService::class);
        $key = (string) Str::uuid();
        $trial = $service->trial($user, $workspace, $key);
        $this->assertEquals($trial, $service->trial($user, $workspace, $key));
        $promo = (string) Str::uuid();
        DB::table('promo_codes')->insert(['id' => $promo, 'code_hash' => hash('sha256', 'FREE'), 'label' => 'Free', 'starts_at' => now()->subDay(), 'ends_at' => now()->addDay(), 'max_uses' => 1]);
        DB::table('promo_benefits')->insert(['id' => (string) Str::uuid(), 'promo_code_id' => $promo, 'type' => 'free_access', 'duration_days' => 30, 'plan_id' => $plan]);
        $promoKey = (string) Str::uuid();
        $redemption = $service->redeemPromo($user, $workspace, $promoKey, 'free');
        $this->assertEquals($redemption, $service->redeemPromo($user, $workspace, $promoKey, 'FREE'));
        $this->assertSame(1, (int) DB::table('promo_codes')->where('id', $promo)->value('uses'));
        $this->expectException(ApiException::class);
        $service->redeemPromo($user, $workspace, (string) Str::uuid(), 'FREE');
    }

    public function test_partner_accrual_partial_refund_and_reservation_are_idempotent(): void
    {
        [$user, $workspace, , $price] = $this->fixture();
        $partnerUser = (string) Str::uuid();
        $partner = (string) Str::uuid();
        $program = (string) Str::uuid();
        $version = (string) Str::uuid();
        DB::table('users')->insert(['id' => $partnerUser, 'name' => 'Partner']);
        DB::table('partners')->insert(['id' => $partner, 'user_id' => $partnerUser, 'status' => 'active', 'payout_profile_reference' => 'verified-profile']);
        DB::table('partner_programs')->insert(['id' => $program, 'name' => 'Program', 'active' => true]);
        DB::table('partner_program_versions')->insert(['id' => $version, 'program_id' => $program, 'version' => 1, 'status' => 'published', 'rules' => '{"payments":"recurring","attribution_window_days":30}', 'rate_bps' => 2000, 'hold_days' => 0, 'minimum_payout_minor' => 100]);
        DB::table('referral_codes')->insert(['id' => (string) Str::uuid(), 'partner_id' => $partner, 'program_version_id' => $version, 'code_hash' => hash('sha256', 'PARTNER')]);
        $partners = app(PartnerService::class);
        $partners->attribute($user, $workspace, 'partner');
        app(BillingService::class)->checkout($user, $workspace, (string) Str::uuid(), $price);
        $payment = DB::table('payments')->where('workspace_id', $workspace)->first();
        $this->sendCallback($payment->external_id, 'settled');
        $this->sendCallback($payment->external_id, 'settled');
        $this->assertSame(2000, $partners->summary($partnerUser)['payable_minor']);
        $this->sendCallback($payment->external_id, 'refund-half', 'refunded', 5000, 'half');
        $this->assertSame(1000, $partners->summary($partnerUser)['payable_minor']);
        $key = (string) Str::uuid();
        $payout = $partners->requestPayout($partnerUser, $key, 1000);
        $this->assertSame($payout, $partners->requestPayout($partnerUser, $key, 1000));
        $this->assertSame(0, $partners->summary($partnerUser)['payable_minor']);
        $this->assertSame(1, DB::table('partner_payouts')->where('partner_id', $partner)->count());
        $this->sendCallback($payment->external_id, 'refund-last', 'refunded', 5000, 'last');
        $this->assertSame(-1000, (int) DB::table('partner_ledger_entries')->where('partner_id', $partner)->sum('amount_minor'));
    }

    public function test_discount_changes_checkout_amount_and_free_months_extend_access(): void
    {
        [$user, $workspace, , $price] = $this->fixture();
        $service = app(BillingService::class);
        $promo = (string) Str::uuid();
        DB::table('promo_codes')->insert(['id' => $promo, 'code_hash' => hash('sha256', 'SAVE'), 'label' => 'Save', 'starts_at' => now()->subDay(), 'ends_at' => now()->addDay(), 'max_uses' => 10, 'budget_minor' => 5000]);
        DB::table('promo_benefits')->insert(['id' => (string) Str::uuid(), 'promo_code_id' => $promo, 'type' => 'discount', 'discount_bps' => 2500, 'cycles' => 1]);
        $service->redeemPromo($user, $workspace, (string) Str::uuid(), 'save');
        $service->checkout($user, $workspace, (string) Str::uuid(), $price);
        $payment = DB::table('payments')->where('workspace_id', $workspace)->first();
        $this->assertSame(7500, (int) $payment->amount_minor);
        $this->assertSame(2500, (int) DB::table('promo_codes')->where('id', $promo)->value('spent_minor'));
        $this->sendCallback($payment->external_id, 'discount-settled', 'succeeded', 7500);
        $before = DB::table('subscriptions')->where('id', $payment->subscription_id)->value('period_end');
        $months = (string) Str::uuid();
        DB::table('promo_codes')->insert(['id' => $months, 'code_hash' => hash('sha256', 'MONTHS'), 'label' => 'Months', 'starts_at' => now()->subDay(), 'ends_at' => now()->addDay()]);
        DB::table('promo_benefits')->insert(['id' => (string) Str::uuid(), 'promo_code_id' => $months, 'type' => 'free_months', 'months' => 2]);
        $service->redeemPromo($user, $workspace, (string) Str::uuid(), 'months');
        $after = DB::table('subscriptions')->where('id', $payment->subscription_id)->value('period_end');
        $this->assertTrue(CarbonImmutable::parse($after)->equalTo(CarbonImmutable::parse($before)->addMonthsNoOverflow(2)));
    }

    public function test_foreign_user_cannot_buy_for_another_workspace(): void
    {
        [, $workspace, , $price] = $this->fixture();
        $stranger = (string) Str::uuid();
        DB::table('users')->insert(['id' => $stranger, 'name' => 'Stranger']);
        $this->expectException(ApiException::class);
        app(BillingService::class)->checkout($stranger, $workspace, (string) Str::uuid(), $price);
    }

    public function test_opted_in_renewal_is_idempotent_and_cancel_blocks_next_charge(): void
    {
        [$user, $workspace, , $price] = $this->fixture();
        config(['billing.renewals_enabled' => true]);
        $service = app(BillingService::class);
        $service->checkout($user, $workspace, (string) Str::uuid(), $price, true);
        $first = DB::table('payments')->where('workspace_id', $workspace)->first();
        $this->sendCallback($first->external_id, 'first-settled', savedMethod: 'saved-method');
        $subscription = DB::table('subscriptions')->where('id', $first->subscription_id)->first();
        $this->assertNotNull($subscription->payment_method_id);
        DB::table('subscriptions')->where('id', $subscription->id)->update(['period_start' => now()->subMonths(2), 'period_end' => now()->subDay()]);
        $this->assertSame(1, $service->renewDue()['created']);
        $this->assertSame(0, $service->renewDue()['created']);
        $renewal = DB::table('payments')->where('subscription_id', $subscription->id)->where('recurring', true)->first();
        $this->assertSame('pending', $renewal->status);
        $this->sendCallback($renewal->external_id, 'renewed');
        $this->assertTrue(CarbonImmutable::parse($service->subscriptionView($subscription->id)['period_end'])->isFuture());
        $this->sendCallback($first->external_id, 'old-refund', 'refunded', 10000, 'old');
        $this->assertSame('active', $service->subscriptionView($subscription->id)['status']);
        $service->cancel($user, $workspace, (string) Str::uuid());
        DB::table('subscriptions')->where('id', $subscription->id)->update(['period_start' => now()->subMonths(2), 'period_end' => now()->subDay()]);
        $this->assertSame(0, $service->renewDue()['created']);
    }

    public function test_reconciliation_recovers_a_missing_yookassa_callback(): void
    {
        [$user, $workspace, , $price] = $this->fixture();
        config(['billing.yookassa.enabled' => true, 'billing.yookassa.shop_id' => 'shop', 'billing.yookassa.secret' => 'secret', 'billing.yookassa.return_url' => 'https://example.invalid/return']);
        DB::table('plan_prices')->where('id', $price)->update(['provider' => 'yookassa']);
        Http::preventStrayRequests();
        Http::fake([
            'https://api.yookassa.ru/v3/payments' => Http::response(['id' => 'provider-payment', 'confirmation' => ['confirmation_url' => 'https://example.invalid/pay']]),
            'https://api.yookassa.ru/v3/payments/provider-payment' => Http::response(['id' => 'provider-payment', 'status' => 'succeeded', 'amount' => ['value' => '100.00', 'currency' => 'RUB']]),
        ]);
        $service = app(BillingService::class);
        $service->checkout($user, $workspace, (string) Str::uuid(), $price);
        DB::table('payments')->where('workspace_id', $workspace)->update(['created_at' => now()->subMinutes(5)]);
        $this->assertSame(['checked' => 1, 'settled' => 1, 'failed' => 0], $service->reconcilePending());
        $this->assertSame(0, $service->reconcilePending()['checked']);
        $this->assertSame('succeeded', DB::table('payments')->where('workspace_id', $workspace)->value('status'));
    }
}
