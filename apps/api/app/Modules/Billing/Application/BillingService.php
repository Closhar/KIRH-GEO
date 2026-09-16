<?php

declare(strict_types=1);

namespace App\Modules\Billing\Application;

use App\Modules\Access\EntitlementService;
use App\Modules\Access\PermissionService;
use App\Modules\Billing\Domain\BillingMath;
use App\Modules\Billing\Infrastructure\BillingProviderRegistry;
use App\Modules\Partners\Application\PartnerService;
use App\Support\ApiException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

final class BillingService
{
    public function __construct(private PermissionService $permissions, private EntitlementService $entitlements, private BillingProviderRegistry $providers) {}

    public function operate(string $userId, string $workspaceId, string $key, string $operation, array $payload, callable $callback): array
    {
        $this->permissions->assert($userId, $workspaceId, 'billing.manage');

        return DB::transaction(function () use ($userId, $workspaceId, $key, $operation, $payload, $callback): array {
            DB::table('workspaces')->where('id', $workspaceId)->lockForUpdate()->first();
            $hash = hash('sha256', json_encode([$operation, $payload], JSON_THROW_ON_ERROR));
            $previous = DB::table('billing_operations')->where('workspace_id', $workspaceId)->where('idempotency_key', $key)->first();
            if ($previous) {
                if ($previous->user_id !== $userId || ! hash_equals($previous->payload_hash, $hash)) {
                    throw new ApiException('IDEMPOTENCY_CONFLICT', 'Key already belongs to another request.', 409);
                }

                return json_decode($previous->response, true, flags: JSON_THROW_ON_ERROR);
            }
            $result = $callback();
            DB::table('billing_operations')->insert(['workspace_id' => $workspaceId, 'user_id' => $userId, 'idempotency_key' => $key, 'operation' => $operation, 'payload_hash' => $hash, 'response' => json_encode($result, JSON_THROW_ON_ERROR), 'created_at' => now()]);
            $this->event($workspaceId, $userId, 'billing.'.$operation, $workspaceId);

            return $result;
        }, 3);
    }

    public function checkout(string $userId, string $workspaceId, string $key, string $priceId, bool $autoRenew = false): array
    {
        return $this->operate($userId, $workspaceId, $key, 'checkout', ['plan_price_id' => $priceId, 'auto_renew' => $autoRenew], function () use ($workspaceId, $key, $priceId, $autoRenew): array {
            if ($autoRenew && ! config('billing.renewals_enabled')) {
                throw new ApiException('RENEWALS_UNAVAILABLE', 'Automatic renewal is not enabled.', 422);
            }
            $price = DB::table('plan_prices as pp')->join('plans as p', 'p.id', '=', 'pp.plan_id')->where('pp.id', $priceId)->where('pp.active', true)->where('p.status', 'published')->select('pp.*')->first();
            if (! $price || $price->amount_minor < 1 || $price->amount_minor > 1000000000) {
                throw new ApiException('PRICE_UNAVAILABLE', 'Published payable price is unavailable.', 422);
            }
            $subscription = $this->currentSubscription($workspaceId);
            if ($subscription && $subscription->status !== 'trialing') {
                throw new ApiException('SUBSCRIPTION_EXISTS', 'Workspace already has a current subscription.', 409);
            }
            if ($subscription) {
                DB::table('subscriptions')->where('id', $subscription->id)->update(['status' => 'expired', 'updated_at' => now()]);
                $this->entitlements->revokeSource($workspaceId, 'subscription', $subscription->id);
            }
            $discount = $this->applyDiscount($workspaceId, (int) $price->amount_minor, $price->currency);
            if ($discount['amount'] < 1) {
                throw new ApiException('ZERO_CHECKOUT', 'Use a free-access promotion for a fully free period.', 422);
            }
            $operationId = Uuid::uuid5(Uuid::NAMESPACE_URL, $workspaceId.':checkout:'.$key)->toString();
            $checkout = $this->providers->get($price->provider)->createCheckout($operationId, $discount['amount'], $price->currency, $autoRenew);
            $id = (string) Str::uuid();
            DB::table('subscriptions')->insert(['id' => $id, 'workspace_id' => $workspaceId, 'plan_price_id' => $priceId, 'provider' => $price->provider, 'external_id' => $checkout['external_id'], 'status' => 'pending', 'period_start' => now(), 'period_end' => BillingMath::periodEnd(CarbonImmutable::now(), $price->interval), 'cancel_at_period_end' => ! $autoRenew, 'auto_renew' => $autoRenew]);
            DB::table('payments')->insert(['id' => (string) Str::uuid(), 'workspace_id' => $workspaceId, 'subscription_id' => $id, 'provider' => $price->provider, 'external_id' => $checkout['external_id'], 'idempotency_key' => $operationId, 'amount_minor' => $discount['amount'], 'currency' => $price->currency, 'status' => 'pending']);

            return ['checkout_id' => $id, 'url' => $checkout['url']];
        });
    }

    public function cancel(string $userId, string $workspaceId, string $key): array
    {
        return $this->operate($userId, $workspaceId, $key, 'cancel', [], function () use ($workspaceId): array {
            $subscription = $this->currentSubscription($workspaceId);
            if (! $subscription) {
                throw new ApiException('NOT_FOUND', 'Subscription unavailable.', 404);
            }
            DB::table('subscriptions')->where('id', $subscription->id)->update(['cancel_at_period_end' => true, 'auto_renew' => false, 'revision' => $subscription->revision + 1, 'updated_at' => now()]);

            return $this->subscriptionView($subscription->id);
        });
    }

    public function trial(string $userId, string $workspaceId, string $key): array
    {
        return $this->operate($userId, $workspaceId, $key, 'trial', [], function () use ($workspaceId): array {
            $planId = $this->setting('trial_plan_id', config('billing.trial_plan_id'));
            $days = (int) $this->setting('trial_days', config('billing.trial_days'));
            $campaign = (string) config('billing.trial_campaign');
            if (! $planId || $days < 1 || $days > 90 || ! DB::table('plans')->where('id', $planId)->where('status', 'published')->exists()) {
                throw new ApiException('TRIAL_UNAVAILABLE', 'Trial is not configured.', 422);
            }
            // The same billing owner cannot get a new initial trial by creating another workspace.
            $billingOwnerId = DB::table('workspaces')->where('id', $workspaceId)->value('billing_owner_user_id');
            DB::table('users')->where('id', $billingOwnerId)->lockForUpdate()->first();
            $used = DB::table('trials as t')->join('workspaces as w', 'w.id', '=', 't.workspace_id')->where('t.campaign_id', $campaign)->where(fn ($q) => $q->where('t.workspace_id', $workspaceId)->orWhere('w.billing_owner_user_id', $billingOwnerId))->exists();
            if ($used || $this->currentSubscription($workspaceId)) {
                throw new ApiException('TRIAL_INELIGIBLE', 'Trial was already used or a subscription exists.', 409);
            }
            $price = DB::table('plan_prices')->where('plan_id', $planId)->where('active', true)->where('interval', 'month')->first();
            if (! $price) {
                throw new ApiException('TRIAL_UNAVAILABLE', 'Trial plan has no monthly price.', 422);
            }
            $id = (string) Str::uuid();
            $subscriptionId = (string) Str::uuid();
            $end = now()->addDays($days);
            DB::table('trials')->insert(['id' => $id, 'workspace_id' => $workspaceId, 'campaign_id' => $campaign, 'starts_at' => now(), 'ends_at' => $end, 'status' => 'active']);
            DB::table('subscriptions')->insert(['id' => $subscriptionId, 'workspace_id' => $workspaceId, 'plan_price_id' => $price->id, 'provider' => 'trial', 'status' => 'trialing', 'period_start' => now(), 'period_end' => $end, 'cancel_at_period_end' => true]);
            $this->entitlements->grantPlan($workspaceId, $planId, 'trial', $id, $end->toIso8601String());

            return $this->subscriptionView($subscriptionId);
        });
    }

    public function redeemPromo(string $userId, string $workspaceId, string $key, string $code): array
    {
        $hash = hash('sha256', mb_strtoupper(trim($code)));

        return $this->operate($userId, $workspaceId, $key, 'promo', ['code_hash' => $hash], function () use ($workspaceId, $userId, $key, $hash): array {
            $promo = DB::table('promo_codes')->where('code_hash', $hash)->where('active', true)->where('starts_at', '<=', now())->where('ends_at', '>', now())->lockForUpdate()->first();
            if (! $promo || ($promo->max_uses !== null && $promo->uses >= $promo->max_uses)) {
                throw new ApiException('PROMO_UNAVAILABLE', 'Promotion is unavailable.', 422);
            }
            $redemptions = DB::table('promo_redemptions')->where('promo_code_id', $promo->id);
            if ((clone $redemptions)->where('user_id', $userId)->count() >= $promo->per_user_limit || (clone $redemptions)->where('workspace_id', $workspaceId)->count() >= $promo->per_workspace_limit) {
                throw new ApiException('PROMO_LIMIT', 'Promotion redemption limit reached.', 409);
            }
            $eligibility = json_decode($promo->eligibility, true);
            if (($eligibility['new_customers_only'] ?? false) && DB::table('payments')->where('workspace_id', $workspaceId)->whereNotNull('paid_at')->exists()) {
                throw new ApiException('PROMO_INELIGIBLE', 'Promotion requires a new customer.', 422);
            }
            if (array_diff(array_keys($eligibility), ['new_customers_only'])) {
                throw new ApiException('PROMO_UNSUPPORTED_RULE', 'Promotion contains unsupported eligibility rules.', 422);
            }
            $benefits = DB::table('promo_benefits')->where('promo_code_id', $promo->id)->get();
            if ($benefits->count() !== 1) {
                throw new ApiException('PROMO_UNSUPPORTED_COMBINATION', 'Use one benefit per promotion in this release.', 422);
            }
            $benefit = $benefits->first();
            $id = (string) Str::uuid();
            $applicationId = (string) Str::uuid();
            $end = null;
            if ($benefit->type === 'free_access') {
                $end = now()->addDays($benefit->duration_days);
                if (! DB::table('plans')->where('id', $benefit->plan_id)->where('status', 'published')->exists()) {
                    throw new ApiException('PROMO_UNAVAILABLE', 'Promotion plan is unavailable.', 422);
                }
                $this->entitlements->grantPlan($workspaceId, $benefit->plan_id, 'promo', $id, $end->toIso8601String());
            } elseif ($benefit->type === 'free_months') {
                $subscription = $this->currentSubscription($workspaceId);
                if (! $subscription || ! in_array($subscription->status, ['active', 'trialing'], true)) {
                    throw new ApiException('PROMO_INELIGIBLE', 'An active subscription is required for free months.', 422);
                }
                $end = CarbonImmutable::parse($subscription->period_end)->addMonthsNoOverflow($benefit->months);
                DB::table('subscriptions')->where('id', $subscription->id)->update(['period_end' => $end, 'revision' => $subscription->revision + 1, 'updated_at' => now()]);
                $planId = DB::table('plan_prices')->where('id', $subscription->plan_price_id)->value('plan_id');
                $this->entitlements->grantPlan($workspaceId, $planId, 'promo', $id, $end->toIso8601String());
            }
            DB::table('promo_redemptions')->insert(['id' => $id, 'promo_code_id' => $promo->id, 'workspace_id' => $workspaceId, 'user_id' => $userId, 'idempotency_key' => $key]);
            DB::table('promotion_applications')->insert(['id' => $applicationId, 'redemption_id' => $id, 'benefit_id' => $benefit->id, 'snapshot' => json_encode((array) $benefit, JSON_THROW_ON_ERROR), 'starts_at' => now(), 'ends_at' => $end ?? $promo->ends_at, 'cycles_remaining' => $benefit->cycles ?? 1, 'status' => 'active']);
            DB::table('promo_codes')->where('id', $promo->id)->increment('uses');

            return ['redemption_id' => $id, 'type' => $benefit->type, 'effective_until' => $end?->toIso8601String() ?? CarbonImmutable::parse($promo->ends_at)->toIso8601String()];
        });
    }

    private function applyDiscount(string $workspaceId, int $amount, string $currency): array
    {
        $applications = DB::table('promotion_applications as a')->join('promo_redemptions as r', 'r.id', '=', 'a.redemption_id')->where('r.workspace_id', $workspaceId)->where('a.status', 'active')->where('a.starts_at', '<=', now())->where('a.ends_at', '>', now())->where('a.cycles_remaining', '>', 0)->orderBy('a.starts_at')->select('a.*', 'r.promo_code_id')->get();
        foreach ($applications as $application) {
            $benefit = json_decode($application->snapshot, true);
            if ($benefit['type'] !== 'discount') {
                continue;
            }
            if (($benefit['currency'] ?? $currency) !== $currency) {
                throw new ApiException('PROMO_CURRENCY', 'Discount currency does not match checkout.', 422);
            }
            $final = BillingMath::discountedAmount($amount, isset($benefit['discount_bps']) ? (int) $benefit['discount_bps'] : null, isset($benefit['amount_minor']) ? (int) $benefit['amount_minor'] : null);
            $promo = DB::table('promo_codes')->where('id', $application->promo_code_id)->lockForUpdate()->first();
            if ($promo->budget_minor !== null && $promo->spent_minor + $amount - $final > $promo->budget_minor) {
                throw new ApiException('PROMO_BUDGET', 'Promotion budget has been exhausted.', 409);
            }
            DB::table('promo_codes')->where('id', $promo->id)->increment('spent_minor', $amount - $final);
            DB::table('promotion_applications')->where('id', $application->id)->update(['cycles_remaining' => $application->cycles_remaining - 1, 'status' => $application->cycles_remaining === 1 ? 'exhausted' : 'active']);

            return ['amount' => $final];
        }

        return ['amount' => $amount];
    }

    public function webhook(string $provider, string $rawBody, array $headers): void
    {
        if (strlen($rawBody) > 262144) {
            throw new ApiException('PAYLOAD_TOO_LARGE', 'Callback is too large.', 413);
        }
        $event = $this->providers->get($provider)->verifyWebhook($rawBody, $headers);
        // Persist normalized authoritative values. Provider delivery bodies may differ on replay.
        $normalized = json_encode((array) $event, JSON_THROW_ON_ERROR);
        $hash = hash('sha256', $normalized);
        DB::transaction(function () use ($provider, $event, $normalized, $hash): void {
            $payment = DB::table('payments')->where('provider', $provider)->where('external_id', $event->paymentId)->first();
            if (! $payment) {
                throw new ApiException('PAYMENT_UNKNOWN', 'Payment is not registered.', 409);
            }
            // Same lock ordering as checkout prevents workspace/payment deadlocks.
            DB::table('workspaces')->where('id', $payment->workspace_id)->lockForUpdate()->first();
            $payment = DB::table('payments')->where('id', $payment->id)->lockForUpdate()->first();
            $old = DB::table('payment_webhook_events')->where('provider', $provider)->where('external_event_id', $event->eventId)->first();
            if ($old) {
                if (! hash_equals($old->payload_hash, $hash)) {
                    throw new ApiException('IDEMPOTENCY_CONFLICT', 'Payment event identity conflict.', 409);
                }

                return;
            }
            if ($event->currency !== $payment->currency || ($event->status !== 'refunded' && $event->amountMinor !== (int) $payment->amount_minor)) {
                throw new ApiException('PAYMENT_MISMATCH', 'Verified payment does not match checkout.', 409);
            }
            $inboxId = (string) Str::uuid();
            DB::table('payment_webhook_events')->insert(['id' => $inboxId, 'provider' => $provider, 'external_event_id' => $event->eventId, 'payload_hash' => $hash, 'payload_encrypted' => Crypt::encryptString($normalized), 'status' => 'pending']);
            $subscription = DB::table('subscriptions')->where('id', $payment->subscription_id)->lockForUpdate()->first();
            if ($event->status === 'refunded') {
                if (! in_array($payment->status, ['succeeded', 'partially_refunded', 'refunded'], true)) {
                    // Retry after payment succeeded delivery, never acknowledge and lose an early refund.
                    throw new ApiException('PAYMENT_NOT_SETTLED', 'Payment settlement has not been recorded.', 409);
                }
                $prior = DB::table('refunds')->where('provider', $provider)->where('external_id', $event->refundId)->first();
                if ($prior) {
                    if ((int) $prior->amount_minor !== $event->amountMinor || $prior->payment_id !== $payment->id) {
                        throw new ApiException('REFUND_CONFLICT', 'Refund identity conflict.', 409);
                    }
                } else {
                    $refunded = (int) DB::table('refunds')->where('payment_id', $payment->id)->where('status', 'succeeded')->sum('amount_minor');
                    if ($event->amountMinor < 1 || $refunded + $event->amountMinor > $payment->amount_minor) {
                        throw new ApiException('REFUND_AMOUNT', 'Refund exceeds the settled payment.', 409);
                    }
                    $refundId = (string) Str::uuid();
                    DB::table('refunds')->insert(['id' => $refundId, 'payment_id' => $payment->id, 'provider' => $provider, 'external_id' => $event->refundId, 'amount_minor' => $event->amountMinor, 'status' => 'succeeded']);
                    $full = $refunded + $event->amountMinor === (int) $payment->amount_minor;
                    DB::table('payments')->where('id', $payment->id)->update(['status' => $full ? 'refunded' : 'partially_refunded']);
                    $hasNewerSettledPeriod = DB::table('payments')->where('subscription_id', $subscription->id)->where('id', '<>', $payment->id)->whereIn('status', ['succeeded', 'partially_refunded'])->when($payment->billing_period_end, fn ($q) => $q->where('billing_period_end', '>', $payment->billing_period_end))->exists();
                    if ($full && ! $hasNewerSettledPeriod) {
                        DB::table('subscriptions')->where('id', $subscription->id)->update(['status' => 'canceled', 'updated_at' => now()]);
                        $this->entitlements->revokeSource($payment->workspace_id, 'subscription', $subscription->id);
                    }
                    app(PartnerService::class)->reverseRefund($payment->id, $refundId);
                }
            } elseif ($payment->status === 'pending') {
                DB::table('payments')->where('id', $payment->id)->update(['status' => $event->status, 'paid_at' => $event->status === 'succeeded' ? now() : null]);
                if ($event->status === 'succeeded') {
                    $price = DB::table('plan_prices')->where('id', $subscription->plan_price_id)->first();
                    $start = $payment->billing_period_start ? CarbonImmutable::parse($payment->billing_period_start) : CarbonImmutable::now();
                    $end = $payment->billing_period_end ? CarbonImmutable::parse($payment->billing_period_end) : BillingMath::periodEnd($start, $price->interval);
                    $changes = ['status' => 'active', 'period_start' => $start, 'period_end' => $end, 'revision' => $subscription->revision + 1, 'provider_updated_at' => now(), 'updated_at' => now()];
                    if ($subscription->auto_renew && $event->savedPaymentMethodId !== null) {
                        $method = DB::table('payment_methods')->where('provider', $provider)->where('external_id', $event->savedPaymentMethodId)->first();
                        if ($method && $method->workspace_id !== $payment->workspace_id) {
                            throw new ApiException('PAYMENT_METHOD_OWNERSHIP', 'Payment method belongs to another workspace.', 409);
                        }
                        $methodId = $method?->id ?? (string) Str::uuid();
                        DB::table('payment_methods')->updateOrInsert(['id' => $methodId], ['workspace_id' => $payment->workspace_id, 'provider' => $provider, 'external_id' => $event->savedPaymentMethodId, 'status' => 'active']);
                        $changes['payment_method_id'] = $methodId;
                    } elseif ($subscription->auto_renew && ! $subscription->payment_method_id) {
                        // Unsupported payment method cannot silently promise future renewal.
                        $changes['auto_renew'] = false;
                        $changes['cancel_at_period_end'] = true;
                    }
                    DB::table('subscriptions')->where('id', $subscription->id)->update($changes);
                    $this->entitlements->grantPlan($payment->workspace_id, $price->plan_id, 'subscription', $subscription->id, $end->toIso8601String());
                    foreach (DB::table('trials')->where('workspace_id', $payment->workspace_id)->where('status', 'active')->get() as $trial) {
                        $this->entitlements->revokeSource($payment->workspace_id, 'trial', $trial->id);
                    }
                    DB::table('trials')->where('workspace_id', $payment->workspace_id)->where('status', 'active')->update(['status' => 'converted']);
                    $invoiceId = (string) Str::uuid();
                    DB::table('invoices')->insert(['id' => $invoiceId, 'workspace_id' => $payment->workspace_id, 'subscription_id' => $subscription->id, 'number' => 'GEO-'.$payment->id, 'total_minor' => $payment->amount_minor, 'currency' => $payment->currency]);
                    DB::table('invoice_items')->insert(['id' => (string) Str::uuid(), 'invoice_id' => $invoiceId, 'description' => 'Subscription '.$price->interval, 'quantity' => 1, 'unit_amount_minor' => $payment->amount_minor, 'total_minor' => $payment->amount_minor]);
                    app(PartnerService::class)->accruePayment($payment->id);
                } else {
                    DB::table('subscriptions')->where('id', $subscription->id)->update(['status' => $payment->recurring ? 'past_due' : 'canceled', 'updated_at' => now()]);
                }
            } // Terminal payment states never roll back on a late pending/canceled/succeeded callback.
            DB::table('payment_webhook_events')->where('id', $inboxId)->update(['status' => 'processed', 'processed_at' => now(), 'attempts' => 1]);
            $this->event($payment->workspace_id, null, 'billing.payment_verified', $payment->id);
        }, 3);
    }

    public function subscriptionView(string $id): array
    {
        $row = DB::table('subscriptions')->where('id', $id)->first();

        return ['id' => $row->id, 'status' => $row->status, 'period_end' => CarbonImmutable::parse($row->period_end)->toIso8601String(), 'cancel_at_period_end' => (bool) $row->cancel_at_period_end, 'auto_renew' => (bool) $row->auto_renew];
    }

    /** Scheduler calls every minute; provider idempotency protects crash/retry after API success. */
    public function renewDue(int $limit = 100): array
    {
        if (! config('billing.renewals_enabled')) {
            return ['created' => 0, 'failed' => 0];
        }
        $totals = ['created' => 0, 'failed' => 0];
        $ids = DB::table('subscriptions')->where('status', 'active')->where('auto_renew', true)->where('cancel_at_period_end', false)->whereNotNull('payment_method_id')->where('period_end', '<=', now())->limit(max(1, min(1000, $limit)))->pluck('id');
        foreach ($ids as $id) {
            try {
                $created = DB::transaction(function () use ($id): bool {
                    $unlocked = DB::table('subscriptions')->where('id', $id)->first();
                    DB::table('workspaces')->where('id', $unlocked->workspace_id)->lockForUpdate()->first();
                    $subscription = DB::table('subscriptions')->where('id', $id)->lockForUpdate()->first();
                    if ($subscription->status !== 'active' || ! $subscription->auto_renew || $subscription->cancel_at_period_end || CarbonImmutable::parse($subscription->period_end)->isFuture()) {
                        return false;
                    }
                    $key = Uuid::uuid5(Uuid::NAMESPACE_URL, 'renewal:'.$id.':'.CarbonImmutable::parse($subscription->period_end)->toIso8601String())->toString();
                    if (DB::table('payments')->where('idempotency_key', $key)->exists()) {
                        return false;
                    }
                    $method = DB::table('payment_methods')->where('id', $subscription->payment_method_id)->where('workspace_id', $subscription->workspace_id)->where('status', 'active')->first();
                    if (! $method) {
                        return false;
                    }
                    // Published price snapshot is retained; future catalog versions do not alter a subscription.
                    $price = DB::table('plan_prices')->where('id', $subscription->plan_price_id)->first();
                    $discount = $this->applyDiscount($subscription->workspace_id, (int) $price->amount_minor, $price->currency);
                    if ($discount['amount'] < 1) {
                        throw new ApiException('ZERO_RENEWAL', 'Free-access promotion required for zero renewal.', 422);
                    }
                    $charge = $this->providers->get($subscription->provider)->chargeSavedMethod($key, $method->external_id, $discount['amount'], $price->currency);
                    $start = CarbonImmutable::now();
                    DB::table('payments')->insert(['id' => (string) Str::uuid(), 'workspace_id' => $subscription->workspace_id, 'subscription_id' => $id, 'provider' => $subscription->provider, 'external_id' => $charge['external_id'], 'idempotency_key' => $key, 'amount_minor' => $discount['amount'], 'currency' => $price->currency, 'status' => 'pending', 'recurring' => true, 'billing_period_start' => $start, 'billing_period_end' => BillingMath::periodEnd($start, $price->interval)]);
                    $this->event($subscription->workspace_id, null, 'billing.renewal_requested', $id);

                    return true;
                }, 3);
                $totals['created'] += (int) $created;
            } catch (\Throwable $exception) {
                $totals['failed']++;
                // Never log provider exception bodies, method IDs, or response payloads.
                DB::table('system_events')->insert(['id' => (string) Str::uuid(), 'component' => 'billing', 'severity' => 'error', 'event_code' => 'RENEWAL_FAILED', 'redacted_context' => json_encode(['subscription_id' => $id, 'code' => $exception instanceof ApiException ? $exception->errorCode : 'DEPENDENCY_FAILURE'])]);
            }
        }

        return $totals;
    }

    private function currentSubscription(string $workspaceId): ?object
    {
        // Expiry is enforced synchronously as well as by scheduled maintenance.
        DB::table('subscriptions')->where('workspace_id', $workspaceId)->where('period_end', '<=', now())->where('auto_renew', false)->whereIn('status', ['active', 'trialing', 'past_due', 'grace', 'paused'])->whereNotExists(fn ($q) => $q->selectRaw('1')->from('payments')->whereColumn('payments.subscription_id', 'subscriptions.id')->where('payments.status', 'pending'))->update(['status' => 'expired', 'updated_at' => now()]);

        return DB::table('subscriptions')->where('workspace_id', $workspaceId)->whereIn('status', ['pending', 'active', 'trialing', 'past_due', 'grace', 'paused'])->lockForUpdate()->first();
    }

    /** Recover missed provider callbacks without trusting locally assumed payment state. */
    public function reconcilePending(int $limit = 100): array
    {
        $totals = ['checked' => 0, 'settled' => 0, 'failed' => 0];
        if (! config('billing.yookassa.enabled')) {
            return $totals;
        }
        $payments = DB::table('payments')->where('provider', 'yookassa')->where('status', 'pending')->where('created_at', '<=', now()->subMinutes(2))->orderBy('created_at')->limit(max(1, min(1000, $limit)))->get();
        foreach ($payments as $payment) {
            $totals['checked']++;
            try {
                // This is only a retrieval hint. Adapter fetches current provider status/amount.
                $this->webhook('yookassa', json_encode(['event' => 'payment.succeeded', 'object' => ['id' => $payment->external_id]], JSON_THROW_ON_ERROR), []);
                $totals['settled']++;
            } catch (ApiException $exception) {
                if ($exception->errorCode !== 'WEBHOOK_UNVERIFIED' || $exception->status !== 409) {
                    $totals['failed']++;
                }
            } catch (\Throwable) {
                $totals['failed']++;
            }
        }

        return $totals;
    }

    private function setting(string $key, mixed $fallback): mixed
    {
        $value = DB::table('application_settings')->where('namespace', 'billing')->where('key', $key)->value('value');

        return $value === null ? $fallback : json_decode($value, true);
    }

    private function event(string $workspaceId, ?string $actorId, string $type, string $target): void
    {
        DB::table('audit_logs')->insert(['id' => (string) Str::uuid(), 'workspace_id' => $workspaceId, 'actor_id' => $actorId, 'action' => $type, 'target_type' => 'billing', 'target_id' => $target]);
        DB::table('outbox_events')->insert(['id' => (string) Str::uuid(), 'workspace_id' => $workspaceId, 'type' => $type, 'aggregate_id' => $target, 'payload' => json_encode(['target_id' => $target], JSON_THROW_ON_ERROR)]);
    }
}
