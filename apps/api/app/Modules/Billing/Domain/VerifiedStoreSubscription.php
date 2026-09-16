<?php

declare(strict_types=1);

namespace App\Modules\Billing\Domain;

final readonly class VerifiedStoreSubscription
{
    public function __construct(public string $originalTransactionId, public string $productId, public string $expiresAt, public bool $revoked) {}
}
