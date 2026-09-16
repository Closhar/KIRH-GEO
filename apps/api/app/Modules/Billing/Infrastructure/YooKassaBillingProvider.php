<?php

declare(strict_types=1);

namespace App\Modules\Billing\Infrastructure;

use App\Modules\Billing\Domain\BillingProvider;
use App\Modules\Billing\Domain\VerifiedPaymentEvent;
use App\Support\ApiException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/** Hosted checkout and explicit opt-in saved-method renewal. No card details are stored. */
final class YooKassaBillingProvider implements BillingProvider
{
    private function client(): PendingRequest
    {
        if (! config('billing.yookassa.enabled') || ! config('billing.yookassa.shop_id') || ! config('billing.yookassa.secret')) {
            throw new ApiException('PROVIDER_UNAVAILABLE', 'Payment provider is not configured.', 503);
        }

        return Http::baseUrl('https://api.yookassa.ru/v3')->withBasicAuth((string) config('billing.yookassa.shop_id'), (string) config('billing.yookassa.secret'))->acceptJson()->timeout(15)->connectTimeout(5);
    }

    public function createCheckout(string $operationId, int $amountMinor, string $currency, bool $savePaymentMethod = false): array
    {
        $returnUrl = (string) config('billing.yookassa.return_url');
        if (! str_starts_with($returnUrl, 'https://')) {
            throw new ApiException('PROVIDER_UNAVAILABLE', 'Payment return URL is not configured.', 503);
        }
        $response = $this->client()->withHeaders(['Idempotence-Key' => $operationId])->post('/payments', [
            'amount' => ['value' => intdiv($amountMinor, 100).'.'.str_pad((string) ($amountMinor % 100), 2, '0', STR_PAD_LEFT), 'currency' => $currency],
            'capture' => true, 'confirmation' => ['type' => 'redirect', 'return_url' => $returnUrl],
            'save_payment_method' => $savePaymentMethod,
            'description' => 'KIRH GEO subscription period',
        ]);
        if (! $response->successful() || ! $response->json('id') || ! $response->json('confirmation.confirmation_url')) {
            throw new ApiException('PROVIDER_UNAVAILABLE', 'Payment provider did not create checkout.', 503);
        }

        return ['external_id' => $response->json('id'), 'url' => $response->json('confirmation.confirmation_url')];
    }

    public function chargeSavedMethod(string $operationId, string $paymentMethodId, int $amountMinor, string $currency): array
    {
        $response = $this->client()->withHeaders(['Idempotence-Key' => $operationId])->post('/payments', [
            'amount' => ['value' => intdiv($amountMinor, 100).'.'.str_pad((string) ($amountMinor % 100), 2, '0', STR_PAD_LEFT), 'currency' => $currency],
            'capture' => true, 'payment_method_id' => $paymentMethodId,
            'description' => 'KIRH GEO subscription renewal',
        ]);
        if (! $response->successful() || ! $response->json('id')) {
            throw new ApiException('PROVIDER_UNAVAILABLE', 'Payment provider did not create renewal.', 503);
        }

        return ['external_id' => $response->json('id')];
    }

    public function verifyWebhook(string $rawBody, array $headers): VerifiedPaymentEvent
    {
        $body = json_decode($rawBody, true);
        $id = $body['object']['id'] ?? '';
        $event = $body['event'] ?? '';
        if (! is_string($id) || ! preg_match('/^[a-zA-Z0-9-]{1,100}$/', $id) || ! in_array($event, ['payment.succeeded', 'payment.canceled', 'refund.succeeded'], true)) {
            throw new ApiException('WEBHOOK_INVALID', 'Unsupported callback.', 422);
        }
        // The incoming amount/status is never trusted. The fixed origin prevents SSRF.
        $refund = $event === 'refund.succeeded';
        $response = $this->client()->get(($refund ? '/refunds/' : '/payments/').$id);
        if (! $response->successful()) {
            throw new ApiException('WEBHOOK_UNVERIFIED', 'Cannot verify payment object.', 503);
        }
        $verified = $response->json();
        $status = $verified['status'] ?? '';
        if (($verified['id'] ?? '') !== $id || ! in_array($status, $refund ? ['succeeded'] : ['succeeded', 'canceled'], true)) {
            throw new ApiException('WEBHOOK_UNVERIFIED', 'Payment object does not have a final state.', 409);
        }
        $value = $verified['amount']['value'] ?? '';
        if (! is_string($value) || ! preg_match('/^(\d{1,8})\.(\d{2})$/', $value, $parts)) {
            throw new ApiException('WEBHOOK_UNVERIFIED', 'Invalid verified payment amount.', 422);
        }
        $paymentId = $refund ? ($verified['payment_id'] ?? '') : $id;
        if (! is_string($paymentId) || $paymentId === '') {
            throw new ApiException('WEBHOOK_UNVERIFIED', 'Invalid verified payment reference.', 422);
        }

        $savedMethod = ! $refund && ($verified['payment_method']['saved'] ?? false) === true ? ($verified['payment_method']['id'] ?? null) : null;

        return new VerifiedPaymentEvent(($refund ? 'refund:' : 'payment:').$id.':'.$status, $paymentId, $refund ? 'refunded' : $status, ((int) $parts[1] * 100) + (int) $parts[2], $verified['amount']['currency'], $refund ? $id : null, is_string($savedMethod) ? $savedMethod : null);
    }
}
