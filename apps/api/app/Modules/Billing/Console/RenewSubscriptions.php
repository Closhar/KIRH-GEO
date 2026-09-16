<?php

declare(strict_types=1);

namespace App\Modules\Billing\Console;

use App\Modules\Billing\Application\BillingService;
use Illuminate\Console\Command;

final class RenewSubscriptions extends Command
{
    protected $signature = 'billing:renew {--limit=100}';

    protected $description = 'Create idempotent charges for opted-in subscriptions due for renewal';

    public function handle(BillingService $service): int
    {
        $counts = $service->renewDue((int) $this->option('limit'));
        $this->info('Renewal requests: '.$counts['created'].'; failures: '.$counts['failed']);

        return $counts['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
