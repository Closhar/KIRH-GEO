<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Privacy\PrivacyService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

final class ProcessPrivacy extends Command
{
    protected $signature = 'geo:process-privacy {--limit=10} {--replay-tombstones}';

    protected $description = 'Export private subject data, erase pending accounts, replay restore tombstones';

    public function handle(PrivacyService $service): int
    {
        if (! DB::selectOne('SELECT pg_try_advisory_lock(81422392) AS acquired')->acquired) {
            return self::SUCCESS;
        }
        try {
            if ($this->option('replay-tombstones')) {
                foreach (DB::table('users')->select('id')->cursor() as $user) {
                    if (DB::table('deletion_tombstones')->where('subject_hash', $service->subjectHash($user->id))->exists()) {
                        $service->erase($user->id);
                    }
                }
            }
            foreach (['deletion_requests', 'export_requests'] as $table) {
                foreach (DB::table($table)->whereIn('status', ['pending', 'processing'])->orderBy('requested_at')->limit(max(1, min(100, (int) $this->option('limit'))))->get() as $request) {
                    try {
                        DB::table($table)->where('id', $request->id)->update(['status' => 'processing']);
                        if ($table === 'deletion_requests') {
                            $service->erase($request->user_id);
                            DB::table($table)->where('id', $request->id)->update(['status' => 'completed', 'finished_at' => now()]);
                        } elseif (DB::table('users')->where('id', $request->user_id)->where('status', 'active')->exists()) {
                            $service->export($request);
                        } else {
                            DB::table($table)->where('id', $request->id)->update(['status' => 'expired']);
                        }
                    } catch (\Throwable $exception) {
                        DB::table($table)->where('id', $request->id)->update(['status' => 'pending']);
                        Log::error('privacy.processing_failed', ['request_id' => $request->id, 'exception_type' => $exception::class]);
                    }
                }
            }
            foreach (DB::table('export_requests')->where('status', 'completed')->where('expires_at', '<=', now())->get() as $request) {
                Storage::disk('local')->delete($request->storage_reference);
                DB::table('export_requests')->where('id', $request->id)->update(['status' => 'expired', 'storage_reference' => null]);
            }

            return self::SUCCESS;
        } finally {
            DB::select('SELECT pg_advisory_unlock(81422392)');
        }
    }
}
