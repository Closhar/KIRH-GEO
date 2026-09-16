<?php

declare(strict_types=1);

namespace App\Modules\Billing\Domain;

interface StorePurchaseProvider
{
    /** Must verify server-side transaction ownership, bundle/package, environment and expiry. */
    public function verifyAndRestore(string $purchaseToken, string $workspaceId): VerifiedStoreSubscription;
}
