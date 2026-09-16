<?php

declare(strict_types=1);

namespace App\Modules\Billing\Infrastructure;

use App\Modules\Billing\Domain\StorePurchaseProvider;
use App\Modules\Billing\Domain\VerifiedStoreSubscription;
use App\Support\ApiException;

/** Explicit release gate: never accept a client assertion as a verified store purchase. */
final class UnconfiguredStorePurchaseProvider implements StorePurchaseProvider
{
    public function verifyAndRestore(string $purchaseToken, string $workspaceId): VerifiedStoreSubscription
    {
        throw new ApiException('STORE_VERIFICATION_UNAVAILABLE', 'Store credentials and server verification adapter are not configured.', 503);
    }
}
