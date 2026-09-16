<?php

declare(strict_types=1);

namespace App\Modules\Billing\Domain;

interface BillingProvider
{
    /** @return array{external_id:string,url:string} */
    public function createCheckout(string $operationId, int $amountMinor, string $currency, bool $savePaymentMethod = false): array;

    /** @return array{external_id:string} */
    public function chargeSavedMethod(string $operationId, string $paymentMethodId, int $amountMinor, string $currency): array;

    /** Must authenticate raw body or retrieve the authoritative object through provider API. */
    public function verifyWebhook(string $rawBody, array $headers): VerifiedPaymentEvent;
}
