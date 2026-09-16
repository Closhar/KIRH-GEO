<?php

declare(strict_types=1);

namespace App\Modules\Billing\Domain;

final readonly class VerifiedPaymentEvent
{
    public function __construct(
        public string $eventId,
        public string $paymentId,
        public string $status,
        public int $amountMinor,
        public string $currency,
        public ?string $refundId = null,
        public ?string $savedPaymentMethodId = null,
    ) {}
}
