<?php

declare(strict_types=1);

namespace App\Modules\Sharing;

use App\Modules\Access\EntitlementService;
use App\Modules\Access\LocationSettings;
use App\Modules\Access\PermissionService;
use App\Support\ApiException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

final class TemporaryShareService
{
    public function create(string $subject, string $workspace, array $data): array
    {
        return DB::transaction(function () use ($subject, $workspace, $data): array {
            app(PermissionService::class)->assert($subject, $workspace, 'sharing.create_temporary');
            DB::table('workspaces')->where('id', $workspace)->lockForUpdate()->first();
            app(EntitlementService::class)->assertCapacity($workspace, 'temporary_shares.max_active', DB::table('temporary_shares')
                ->where('workspace_id', $workspace)->whereNull('revoked_at')->where('expires_at', '>', now())->count());
            $grant = DB::table('sharing_grants')->where('id', $data['grant_id'])->where('workspace_id', $workspace)->where('user_id', $subject)
                ->whereIn('scope', ['current', 'both'])->whereNull('revoked_at')->where('starts_at', '<=', now())
                ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()))->first();
            if (! $grant) {
                throw new ApiException('CONSENT_REQUIRED', 'Active own current-location consent required.');
            }
            $id = (string) Str::uuid();
            $token = bin2hex(random_bytes(32));
            $expiry = now()->addMinutes($data['expires_in_minutes']);
            if ($grant->ends_at && $expiry->gt($grant->ends_at)) {
                $expiry = CarbonImmutable::parse($grant->ends_at);
            }
            DB::table('temporary_shares')->insert(['id' => $id, 'workspace_id' => $workspace, 'issuer_id' => $subject, 'subject_id' => $subject,
                'token_hash' => hash('sha256', $token), 'grant_id' => $grant->id, 'expires_at' => $expiry,
                'passcode_hash' => isset($data['passcode']) ? Hash::make($data['passcode']) : null]);
            $this->assertActive(DB::table('temporary_shares')->where('id', $id)->first());
            DB::table('consent_logs')->insert(['workspace_id' => $workspace, 'user_id' => $subject, 'actor_id' => $subject,
                'purpose' => 'temporary_share', 'action' => 'grant', 'audience' => json_encode(['capability_id' => $id]),
                'policy_version' => '1', 'consent_version' => $grant->consent_version]);

            return ['id' => $id, 'token' => $token, 'expires_at' => $expiry->toIso8601String()];
        }, 3);
    }

    public function exchange(string $token, ?string $passcode): array
    {
        $share = DB::table('temporary_shares')->where('token_hash', hash('sha256', $token))->first();
        if (! $share || ($share->passcode_hash && ! Hash::check($passcode ?? '', $share->passcode_hash))) {
            throw new ApiException('SHARE_UNAVAILABLE', 'Share unavailable.', 404);
        }
        $this->assertActive($share);
        $expires = min(now()->addMinutes(15)->timestamp, CarbonImmutable::parse($share->expires_at)->timestamp);

        return ['access_token' => Crypt::encryptString(json_encode(['share_id' => $share->id, 'expires' => $expires], JSON_THROW_ON_ERROR)),
            'expires_in' => max(0, $expires - now()->timestamp), 'token_type' => 'Bearer'];
    }

    public function current(string $session): ?object
    {
        try {
            $data = json_decode(Crypt::decryptString($session), true, flags: JSON_THROW_ON_ERROR);
            if (! isset($data['share_id'], $data['expires']) || ! Str::isUuid($data['share_id']) || $data['expires'] <= now()->timestamp) {
                throw new \UnexpectedValueException;
            }
        } catch (\Throwable) {
            throw new ApiException('SHARE_UNAVAILABLE', 'Share unavailable.', 404);
        }
        $share = DB::table('temporary_shares')->where('id', $data['share_id'])->first();
        if (! $share) {
            throw new ApiException('SHARE_UNAVAILABLE', 'Share unavailable.', 404);
        }
        $this->assertActive($share);

        return DB::table('location_points as p')->join('location_point_audiences as a', fn ($j) => $j->on('a.point_id', '=', 'p.id')->on('a.captured_at', '=', 'p.captured_at'))
            ->join('sharing_grants as g', 'g.id', '=', 'a.grant_id')->where('a.grant_id', $share->grant_id)->where('a.expires_at', '>', now())
            ->where('p.user_id', $share->subject_id)->whereColumn('p.captured_at', '>=', 'g.starts_at')->whereColumn('p.device_id', 'g.device_id')
            ->where('p.captured_at', '>', now()->subSeconds(app(LocationSettings::class)->raw()['current_ttl_seconds']))
            ->selectRaw('p.captured_at,p.received_at,ST_Y(p.position::geometry) AS latitude,ST_X(p.position::geometry) AS longitude,p.accuracy_m,p.battery_pct')
            ->orderByDesc('p.captured_at')->orderByDesc('p.id')->first();
    }

    private function assertActive(object $share): void
    {
        $grant = DB::table('sharing_grants')->where('id', $share->grant_id)->where('user_id', $share->subject_id)->whereNull('revoked_at')->first();
        $preferences = DB::table('location_preferences')->where('user_id', $share->subject_id)->first();
        if ($share->revoked_at || now()->gte($share->expires_at) || ! $grant || CarbonImmutable::parse($grant->starts_at)->isFuture()
            || ($grant->ends_at && now()->gte($grant->ends_at)) || ! $preferences || $preferences->sharing_paused
            || $preferences->primary_device_id !== $grant->device_id
            || ! DB::table('devices')->where('id', $grant->device_id)->whereNull('revoked_at')->exists()) {
            throw new ApiException('SHARE_UNAVAILABLE', 'Share unavailable.', 404);
        }
        app(PermissionService::class)->assert($share->subject_id, $share->workspace_id, 'sharing.create_temporary');
        app(EntitlementService::class)->assert($share->workspace_id, 'location.enabled');
        app(EntitlementService::class)->assert($share->workspace_id, 'temporary_shares.max_active');
    }
}
