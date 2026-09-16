<?php

declare(strict_types=1);

namespace App\Modules\Billing\Infrastructure;

use App\Modules\Billing\Domain\BillingProvider;
use App\Support\ApiException;

final class BillingProviderRegistry
{
    public function get(string $provider): BillingProvider
    {
        return match ($provider) {
            'sandbox' => app(SandboxBillingProvider::class),
            'yookassa' => app(YooKassaBillingProvider::class),
            default => throw new ApiException('PROVIDER_UNSUPPORTED', 'This payment provider is not enabled.', 422),
        };
    }
}
