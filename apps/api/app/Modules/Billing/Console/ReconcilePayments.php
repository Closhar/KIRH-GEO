<?php

declare(strict_types=1);

namespace App\Modules\Billing\Console;

use App\Modules\Billing\Application\BillingService;
use Illuminate\Console\Command;

final class ReconcilePayments extends Command
{
    protected $signature = 'billing:reconcile {--limit=100}';

    protected $description = 'Verify unsettled YooKassa payments to recover missing callbacks';

    public function handle(BillingService $service): int
    {
        $counts = $service->reconcilePending((int) $this->option('limit'));
        $this->info('Payments checked: '.$counts['checked'].'; settled: '.$counts['settled'].'; failed: '.$counts['failed']);

        return $counts['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
