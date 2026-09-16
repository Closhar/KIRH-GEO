<?php

declare(strict_types=1);

namespace App\Modules\Location\Application;

use App\Modules\Access\EntitlementService;
use App\Modules\Access\LocationSettings;
use App\Modules\Access\PermissionService;
use App\Support\ApiException;
use App\Support\CanonicalJson;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

final class IngestBatch
{
    public function __construct(private PermissionService $permissions, private EntitlementService $entitlements, private LocationSettings $settings) {}

    public function handle(string $userId, string $deviceId, array $batch): array
    {
        if ($batch['device_id'] !== $deviceId) {
            throw new ApiException('DEVICE_MISMATCH', 'Устройство не соответствует сессии.');
        }

        return DB::transaction(function () use ($userId, $deviceId, $batch): array {
            $user = DB::table('users')->where('id', $userId)->lockForUpdate()->first();
            $device = DB::table('devices')->where('id', $deviceId)->where('user_id', $userId)->whereNull('revoked_at')->lockForUpdate()->first();
            $preferences = DB::table('location_preferences')->where('user_id', $userId)->first();
            if (! $device || $user->status !== 'active' || ! $preferences || $preferences->sharing_paused || $preferences->primary_device_id !== $deviceId) {
                throw new ApiException('CONSENT_REQUIRED', 'Передача геопозиции выключена.');
            }
            $hash = CanonicalJson::hash($batch);
            $existing = DB::table('location_batches')->where('device_id', $deviceId)->where('client_batch_id', $batch['client_batch_id'])->first();
            if ($existing) {
                if (! hash_equals($existing->payload_hash, $hash)) {
                    throw new ApiException('IDEMPOTENCY_CONFLICT', 'Идентификатор батча уже использован с другими данными.', 409);
                }

                return json_decode($existing->response_summary, true, flags: JSON_THROW_ON_ERROR);
            }
            $grants = DB::table('sharing_grants')->where('user_id', $userId)->whereNull('revoked_at')
                ->where(fn ($q) => $q->whereNull('device_id')->orWhere('device_id', $deviceId))
                ->where('starts_at', '<=', now())->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()))->get();
            $allowedGrants = [];
            foreach ($grants as $grant) {
                try {
                    $this->permissions->assert($userId, $grant->workspace_id, 'location.publish_own', $grant->group_id);
                    $this->entitlements->assert($grant->workspace_id, 'location.enabled');
                    $joinedAt = DB::table('workspace_memberships')->where('workspace_id', $grant->workspace_id)->where('user_id', $userId)->value('joined_at');
                    if (CarbonImmutable::parse($grant->starts_at)->lt($joinedAt)) {
                        continue;
                    }
                    $allowedGrants[] = $grant;
                } catch (ApiException) {
                    // A revoked workspace cannot receive new data; other explicitly granted workspaces may.
                }
            }
            if ($allowedGrants === []) {
                throw new ApiException('CONSENT_REQUIRED', 'Нет действующего разрешения на передачу.');
            }
            $policy = $this->settings->raw();
            $indexed = $batch['points'];
            uasort($indexed, fn ($a, $b) => $this->captureOrder($a) <=> $this->captureOrder($b));
            $results = [];
            foreach ($indexed as $index => $point) {
                $results[$index] = $this->point($userId, $deviceId, (int) $preferences->revision, $point, $allowedGrants, $policy);
            }
            ksort($results);
            $result = ['client_batch_id' => $batch['client_batch_id'], 'results' => array_values($results)];
            DB::table('location_batches')->insert(['device_id' => $deviceId, 'client_batch_id' => $batch['client_batch_id'],
                'payload_hash' => $hash, 'response_summary' => json_encode($result, JSON_THROW_ON_ERROR)]);
            DB::table('devices')->where('id', $deviceId)->update(['last_seen_at' => now()]);
            Log::info('location.batch_accepted', ['device_id' => $deviceId, 'points' => count($results)]);

            return $result;
        }, 3);
    }

    private function point(string $userId, string $deviceId, int $revision, array $point, array $grants, array $policy): array
    {
        $validator = Validator::make($point, [
            'client_point_id' => 'required|uuid', 'captured_at' => 'required|date',
            'latitude' => 'required|numeric|between:-90,90', 'longitude' => 'required|numeric|between:-180,180',
            'accuracy_m' => 'required|numeric|min:0|max:99999', 'battery_pct' => 'nullable|integer|between:0,100',
            'mode' => 'required|in:idle,normal,live,sport,sos', 'consent_revision' => 'required|integer|min:1',
            'altitude_m' => 'nullable|numeric|between:-12000,100000', 'speed_mps' => 'nullable|numeric|between:0,1500',
            'heading' => 'nullable|numeric|min:0|lt:360',
        ]);
        $base = ['client_point_id' => $point['client_point_id'] ?? null];
        $reject = fn (string $code) => [...$base, 'status' => 'permanently_rejected', 'code' => $code];
        if ($validator->fails()) {
            return $reject('INVALID_POINT');
        }
        $at = CarbonImmutable::parse($point['captured_at'])->utc();
        if ($at->lt(now()->subHours($policy['offline_max_hours'])) || $at->gt(now()->addSeconds($policy['future_tolerance_seconds']))) {
            return $reject('POINT_OUTSIDE_TIME_WINDOW');
        }
        if ((int) $point['consent_revision'] !== $revision) {
            return $reject('CONSENT_REVISION_CHANGED');
        }
        $hash = CanonicalJson::hash($point);
        $receipt = DB::table('location_point_receipts')->where('device_id', $deviceId)->where('client_point_id', $point['client_point_id'])->first();
        if ($receipt) {
            return hash_equals($receipt->payload_hash, $hash) ? [...$base, 'status' => 'duplicate'] : $reject('POINT_ID_CONFLICT');
        }
        $audiences = [];
        $frequencyLimited = false;
        foreach ($grants as $grant) {
            if ($at->lt(CarbonImmutable::parse($grant->starts_at)) || ($grant->ends_at && $at->gte(CarbonImmutable::parse($grant->ends_at)))) {
                continue;
            }
            if (! $this->modeAllowed($point['mode'], $grant->workspace_id, $userId, $at)) {
                continue;
            }
            $effective = $this->settings->effective($grant->workspace_id, $userId);
            $interval = $effective['modes'][$point['mode']]['capture_seconds'];
            // Check both neighbours: out-of-order uploads cannot fill gaps beyond the workspace limit.
            // The subject row is locked for the entire batch, serializing concurrent devices/retries.
            if (DB::table('location_points as p')->where('p.user_id', $userId)
                ->where('p.captured_at', '>', $at->subSeconds($interval))->where('p.captured_at', '<', $at->addSeconds($interval))
                ->whereExists(fn ($q) => $q->selectRaw('1')->from('location_point_audiences as a')
                    ->whereColumn('a.point_id', 'p.id')->whereColumn('a.captured_at', 'p.captured_at')->where('a.workspace_id', $grant->workspace_id))->exists()) {
                $frequencyLimited = true;

                continue;
            }
            $audiences[] = ['grant' => $grant, 'expires_at' => $at->addSeconds(max($effective['history_retention_days'] * 86400, $policy['current_ttl_seconds']))];
        }
        if ($audiences === []) {
            return $reject($frequencyLimited ? 'SAMPLING_INTERVAL_NOT_REACHED' : 'CONSENT_OR_MODE_UNAVAILABLE');
        }
        $id = (string) Str::uuid();
        DB::insert('INSERT INTO location_points (id, device_id, user_id, client_point_id, captured_at, position, accuracy_m, altitude_m, speed_mps, heading, battery_pct, mode, consent_version) VALUES (?, ?, ?, ?, ?, ST_SetSRID(ST_MakePoint(?, ?),4326)::geography, ?, ?, ?, ?, ?, ?, ?)', [
            $id, $deviceId, $userId, $point['client_point_id'], $at, $point['longitude'], $point['latitude'],
            $point['accuracy_m'], $point['altitude_m'] ?? null, $point['speed_mps'] ?? null, $point['heading'] ?? null,
            $point['battery_pct'] ?? null, $point['mode'], $revision,
        ]);
        DB::table('location_point_receipts')->insert(['device_id' => $deviceId, 'client_point_id' => $point['client_point_id'],
            'payload_hash' => $hash, 'point_id' => $id, 'point_time' => $at, 'expires_at' => now()->addDays(7)]);
        foreach ($audiences as $audience) {
            DB::table('location_point_audiences')->insert(['captured_at' => $at, 'point_id' => $id,
                'workspace_id' => $audience['grant']->workspace_id, 'grant_id' => $audience['grant']->id, 'expires_at' => $audience['expires_at']]);
        }
        foreach (array_unique(array_map(fn ($a) => $a['grant']->workspace_id, $audiences)) as $workspaceId) {
            DB::table('outbox_events')->insert(['workspace_id' => $workspaceId, 'type' => 'location.accepted', 'aggregate_id' => $id,
                'payload' => json_encode(['point_id' => $id, 'captured_at' => $at->toIso8601String(), 'user_id' => $userId, 'device_id' => $deviceId])]);
        }

        return [...$base, 'status' => 'accepted'];
    }

    private function modeAllowed(string $mode, string $workspaceId, string $userId, CarbonImmutable $at): bool
    {
        if ($mode === 'sos') {
            return DB::table('sos_events')->where('workspace_id', $workspaceId)->where('user_id', $userId)
                ->where('started_at', '<=', $at)->where(fn ($q) => $q->whereNull('ended_at')->orWhere('ended_at', '>', $at))->exists();
        }
        if ($mode === 'live') {
            return $this->entitlements->resolve($workspaceId, 'live.enabled') === true
                && DB::table('live_sessions as s')->join('live_session_participants as p', 'p.session_id', '=', 's.id')
                    ->where('s.workspace_id', $workspaceId)->where('p.user_id', $userId)->whereNotNull('p.accepted_at')
                    ->where('p.accepted_at', '<=', $at)->whereNull('p.ended_at')->where('s.status', 'active')->where('s.expires_at', '>', $at)->exists();
        }

        return true;
    }

    private function captureOrder(array $point): float
    {
        if (! is_string($point['captured_at'] ?? null) || strtotime($point['captured_at']) === false) {
            return PHP_FLOAT_MAX;
        }

        return (float) CarbonImmutable::parse($point['captured_at'])->format('U.u');
    }
}
