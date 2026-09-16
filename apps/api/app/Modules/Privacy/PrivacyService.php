<?php

declare(strict_types=1);

namespace App\Modules\Privacy;

use App\Modules\Consent\ConsentService;
use App\Modules\Sharing\LiveSessionService;
use App\Support\ApiException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class PrivacyService
{
    public function requestExport(string $user): array
    {
        return DB::transaction(function () use ($user): array {
            DB::table('users')->where('id', $user)->lockForUpdate()->first();
            $pending = DB::table('export_requests')->where('user_id', $user)->whereIn('status', ['pending', 'processing'])->first();
            if ($pending) {
                return ['id' => $pending->id, 'status' => $pending->status];
            }
            $id = (string) Str::uuid();
            DB::table('export_requests')->insert(['id' => $id, 'user_id' => $user]);

            return ['id' => $id, 'status' => 'pending'];
        });
    }

    public function requestDeletion(string $user): array
    {
        return DB::transaction(function () use ($user): array {
            DB::table('users')->where('id', $user)->lockForUpdate()->first();
            if (DB::table('workspaces as w')->join('subscriptions as s', 's.workspace_id', '=', 'w.id')
                ->where('w.billing_owner_user_id', $user)->where('s.auto_renew', true)->exists()) {
                throw new ApiException('SUBSCRIPTION_CANCELLATION_REQUIRED', 'Cancel automatic renewal before deleting the billing account.', 409);
            }
            $workspaces = DB::table('workspaces')->where('owner_user_id', $user)->where('status', 'active')->get();
            foreach ($workspaces as $workspace) {
                DB::table('workspaces')->where('id', $workspace->id)->lockForUpdate()->first();
                if (DB::table('workspace_memberships')->where('workspace_id', $workspace->id)->where('user_id', '<>', $user)->where('status', 'active')->whereNull('left_at')->exists()) {
                    throw new ApiException('OWNERSHIP_TRANSFER_REQUIRED', 'Transfer ownership of shared workspaces before deleting your account.', 409);
                }
                if (DB::table('subscriptions')->where('workspace_id', $workspace->id)->where('auto_renew', true)->exists()) {
                    throw new ApiException('SUBSCRIPTION_CANCELLATION_REQUIRED', 'Cancel automatic renewal before deleting the billing account.', 409);
                }
            }
            $existing = DB::table('deletion_requests')->where('user_id', $user)->whereIn('status', ['pending', 'processing'])->first();
            if ($existing) {
                return ['id' => $existing->id, 'status' => $existing->status];
            }
            $id = (string) Str::uuid();
            DB::table('deletion_requests')->insert(['id' => $id, 'user_id' => $user,
                'retention_exceptions' => json_encode(['billing_records', 'consent_audit', 'security_audit'])]);
            // Stop all access immediately. Physical cleanup is durable background work.
            app(ConsentService::class)->pause($user);
            DB::table('auth_sessions')->where('user_id', $user)->update(['revoked_at' => now()]);
            DB::table('users')->where('id', $user)->update(['status' => 'deletion_pending']);
            DB::table('temporary_shares')->where('subject_id', $user)->update(['revoked_at' => now()]);

            return ['id' => $id, 'status' => 'pending', 'retained_categories' => ['billing_records', 'consent_audit', 'security_audit']];
        }, 3);
    }

    public function export(object $request): void
    {
        $file = 'privacy/exports/'.$request->id.'.ndjson';
        Storage::disk('local')->makeDirectory('privacy/exports');
        $handle = fopen(Storage::disk('local')->path($file), 'wb');
        if ($handle === false) {
            throw new \RuntimeException('EXPORT_STORAGE_UNAVAILABLE');
        }
        try {
            $user = DB::table('users')->where('id', $request->user_id)->select('id', 'name', 'email', 'phone', 'locale', 'timezone', 'created_at')->first();
            $this->writeRecord($handle, 'profile', $user);
            foreach (['workspace_memberships', 'sharing_grants', 'consent_logs'] as $table) {
                foreach (DB::table($table)->where('user_id', $request->user_id)->cursor() as $row) {
                    $this->writeRecord($handle, $table, $row);
                }
            }
            foreach (DB::table('location_points')->where('user_id', $request->user_id)
                ->selectRaw('captured_at,received_at,ST_Y(position::geometry) AS latitude,ST_X(position::geometry) AS longitude,accuracy_m,battery_pct,mode')
                ->orderBy('captured_at')->cursor() as $point) {
                $this->writeRecord($handle, 'location_point', $point);
            }
        } finally {
            fclose($handle);
        }
        DB::table('export_requests')->where('id', $request->id)->update(['status' => 'completed', 'finished_at' => now(),
            'expires_at' => now()->addDay(), 'storage_reference' => $file]);
    }

    public function erase(string $user): void
    {
        // Cache deletion is fail-closed: do not report complete until Redis accepted it.
        $workspaces = DB::table('workspace_memberships')->where('user_id', $user)->pluck('workspace_id');
        $devices = DB::table('devices')->where('user_id', $user)->pluck('id');
        foreach ($workspaces as $workspace) {
            foreach ($devices as $device) {
                Redis::del('geo:current:'.$workspace.':'.$user.':'.$device);
            }
        }
        foreach (DB::table('export_requests')->where('user_id', $user)->whereNotNull('storage_reference')->pluck('storage_reference') as $file) {
            Storage::disk('local')->delete($file);
        }
        DB::transaction(function () use ($user, $devices): void {
            DB::table('users')->where('id', $user)->lockForUpdate()->first();
            foreach (DB::table('live_session_participants as p')->join('live_sessions as s', 's.id', '=', 'p.session_id')->where('p.user_id', $user)
                ->whereIn('s.status', ['requested', 'active'])->select('s.id', 's.workspace_id')->get() as $session) {
                app(LiveSessionService::class)->end($user, $session->workspace_id, $session->id);
            }
            DB::table('location_points')->where('user_id', $user)->delete();
            DB::table('location_point_receipts')->whereIn('device_id', $devices)->delete();
            DB::table('location_batches')->whereIn('device_id', $devices)->delete();
            DB::table('device_tokens')->whereIn('device_id', $devices)->delete();
            DB::table('auth_sessions')->where('user_id', $user)->delete();
            DB::table('identity_action_tokens')->where('user_id', $user)->delete();
            DB::table('devices')->where('user_id', $user)->update(['revoked_at' => now()]);
            DB::table('temporary_shares')->where('subject_id', $user)->delete();
            $zones = DB::table('geofences')->where('owner_id', $user)->pluck('id');
            foreach (['geofence_events', 'geofence_states', 'geofence_targets'] as $table) {
                DB::table($table)->where('user_id', $user)->orWhereIn('geofence_id', $zones)->delete();
            }
            DB::table('geofences')->where('owner_id', $user)->delete();
            $activities = DB::table('activity_sessions')->where('user_id', $user)->pluck('id');
            DB::table('activity_laps')->whereIn('session_id', $activities)->delete();
            DB::table('activity_metric_samples')->whereIn('session_id', $activities)->delete();
            DB::table('activity_sessions')->where('user_id', $user)->delete();
            DB::table('sos_acknowledgements')->where('user_id', $user)->orWhereIn('sos_event_id', DB::table('sos_events')->where('user_id', $user)->select('id'))->delete();
            DB::table('sos_events')->where('user_id', $user)->delete();
            DB::table('notification_deliveries')->whereIn('notification_id', DB::table('notifications')->where('user_id', $user)->select('id'))->delete();
            DB::table('notifications')->where('user_id', $user)->delete();
            DB::table('workspace_memberships')->where('user_id', $user)->update(['status' => 'left', 'left_at' => now()]);
            DB::table('group_memberships')->where('user_id', $user)->update(['left_at' => now()]);
            DB::table('grant_recipients')->where('viewer_user_id', $user)->delete();
            DB::table('workspaces')->where('owner_user_id', $user)->update(['status' => 'closed']);
            DB::table('users')->where('id', $user)->update(['name' => 'Удалённый пользователь', 'email' => null, 'phone' => null,
                'password' => null, 'remember_token' => null, 'app_authentication_secret' => null, 'app_authentication_recovery_codes' => null, 'status' => 'blocked']);
            DB::table('export_requests')->where('user_id', $user)->update(['status' => 'expired', 'storage_reference' => null]);
            DB::table('deletion_tombstones')->updateOrInsert(['subject_hash' => $this->subjectHash($user)],
                ['deleted_at' => now(), 'scope' => json_encode(['identity', 'locations', 'devices', 'exports'])]);
        }, 3);
    }

    public function subjectHash(string $user): string
    {
        return hash_hmac('sha256', $user, config('app.key'));
    }

    private function writeRecord($handle, string $type, mixed $data): void
    {
        $line = json_encode(['type' => $type, 'data' => $data], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)."\n";
        if (fwrite($handle, $line) !== strlen($line)) {
            throw new \RuntimeException('EXPORT_WRITE_FAILED');
        }
    }
}
