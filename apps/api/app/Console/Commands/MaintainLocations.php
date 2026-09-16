<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Access\LocationSettings;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class MaintainLocations extends Command
{
    protected $signature = 'geo:maintain-locations {--limit=10000}';

    protected $description = 'Provision UTC partitions and expire location audiences, points and retry receipts';

    public function handle(): int
    {
        $settings = app(LocationSettings::class)->raw();
        $limit = max(1, min(100000, (int) $this->option('limit')));
        // One maintenance owner at a time; lock is scoped to this database connection.
        if (! DB::selectOne('SELECT pg_try_advisory_lock(81422391) AS acquired')->acquired) {
            return self::SUCCESS;
        }
        try {
            for ($month = 0; $month <= 3; $month++) {
                $start = CarbonImmutable::now('UTC')->startOfMonth()->addMonths($month);
                $name = 'location_points_'.$start->format('Y_m');
                $end = $start->addMonth();
                DB::statement("CREATE TABLE IF NOT EXISTS {$name} PARTITION OF location_points FOR VALUES FROM ('{$start->format('Y-m-d')}') TO ('{$end->format('Y-m-d')}')");
            }
            // Shortened retention takes effect even when the original audience TTL was longer.
            foreach (DB::table('workspaces')->pluck('id') as $workspace) {
                $subjects = DB::table('sharing_grants')->where('workspace_id', $workspace)->distinct()->pluck('user_id');
                foreach ($subjects as $subject) {
                    $retention = app(LocationSettings::class)->effective($workspace, $subject)['history_retention_days'];
                    $seconds = max($retention * 86400, (int) $settings['current_ttl_seconds']);
                    DB::update("UPDATE location_point_audiences a SET expires_at=LEAST(a.expires_at,a.captured_at + (? * interval '1 second'))
                        FROM sharing_grants g WHERE a.grant_id=g.id AND a.workspace_id=? AND g.user_id=?", [$seconds, $workspace, $subject]);
                }
            }
            DB::delete('DELETE FROM location_point_audiences WHERE (captured_at,point_id,grant_id) IN
                (SELECT captured_at,point_id,grant_id FROM location_point_audiences WHERE expires_at <= now() ORDER BY expires_at LIMIT ?)', [$limit]);
            DB::delete('DELETE FROM location_points p WHERE (p.captured_at,p.id) IN (SELECT p2.captured_at,p2.id FROM location_points p2
                WHERE NOT EXISTS (SELECT 1 FROM location_point_audiences a WHERE a.point_id=p2.id AND a.captured_at=p2.captured_at)
                ORDER BY p2.captured_at LIMIT ?)', [$limit]);
            $receiptCutoff = now()->subHours(max(168, (int) $settings['offline_max_hours'] + 24));
            DB::table('location_point_receipts')->where('received_at', '<', $receiptCutoff)->where('expires_at', '<=', now())->delete();
            DB::table('location_batches')->where('accepted_at', '<', $receiptCutoff)->delete();
            $this->info('Location retention maintenance complete.');

            return self::SUCCESS;
        } finally {
            DB::select('SELECT pg_advisory_unlock(81422391)');
        }
    }
}
