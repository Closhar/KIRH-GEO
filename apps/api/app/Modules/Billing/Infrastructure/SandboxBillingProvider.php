<?php

declare(strict_types=1);

namespace App\Modules\Billing\Infrastructure;

use App\Modules\Billing\Domain\BillingProvider;
use App\Modules\Billing\Domain\VerifiedPaymentEvent;
use App\Support\ApiException;
use Illuminate\Support\Facades\Validator;

final class SandboxBillingProvider implements BillingProvider
{
    private function secret(): string
    {
        $secret = (string) config('billing.sandbox_webhook_secret');
        if (! app()->environment(['local', 'testing']) || ! config('billing.sandbox_enabled') || strlen($secret) < 32) {
            throw new ApiException('PROVIDER_UNAVAILABLE', 'Sandbox payments are disabled.', 503);
        }

        return $secret;
    }

    public function createCheckout(string $operationId, int $amountMinor, string $currency, bool $savePaymentMethod = false): array
    {
        $this->secret();

        return ['external_id' => $operationId, 'url' => 'https://sandbox.invalid/checkout/'.$operationId];
    }

    public function chargeSavedMethod(string $operationId, string $paymentMethodId, int $amountMinor, string $currency): array
    {
        $this->secret();

        return ['external_id' => $operationId];
    }

    public function verifyWebhook(string $rawBody, array $headers): VerifiedPaymentEvent
    {
        $secret = $this->secret();
        $timestamp = $headers['x-billing-timestamp'][0] ?? '';
        $signature = $headers['x-billing-signature'][0] ?? '';
        if (! ctype_digit($timestamp) || abs(time() - (int) $timestamp) > 300
            || ! hash_equals(hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret), $signature)) {
            throw new ApiException('WEBHOOK_UNVERIFIED', 'Payment callback authentication failed.', 401);
        }
        $body = json_decode($rawBody, true);
        $data = Validator::make(is_array($body) ? $body : [], [
            'event_id' => 'required|string|max:150', 'payment_id' => 'required|string|max:150',
            'status' => 'required|in:succeeded,canceled,refunded', 'amount_minor' => 'required|integer|min:1|max:1000000000',
            'currency' => 'required|in:RUB', 'refund_id' => 'required_if:status,refunded|string|max:150',
            'saved_payment_method_id' => 'sometimes|string|max:150',
        ])->validate();

        return new VerifiedPaymentEvent($data['event_id'], $data['payment_id'], $data['status'], (int) $data['amount_minor'], $data['currency'], $data['refund_id'] ?? null, $data['saved_payment_method_id'] ?? null);
    }
}
