<?php

declare(strict_types=1);

namespace App\Modules\Geofencing;

use App\Modules\Access\EntitlementService;
use App\Modules\Access\LocationSettings;
use App\Modules\Access\PermissionService;
use App\Modules\Consent\ConsentService;
use App\Modules\Notifications\NotificationService;
use App\Support\ApiException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class GeofenceService
{
    public function create(string $actor, string $workspace, array $data): array
    {
        return DB::transaction(function () use ($actor, $workspace, $data): array {
            app(PermissionService::class)->assert($actor, $workspace, 'geofence.manage', $data['group_id'] ?? null);
            if (isset($data['group_id']) && ! DB::table('groups')->where('workspace_id', $workspace)->where('id', $data['group_id'])->exists()) {
                throw new ApiException('NOT_FOUND', 'Group unavailable.', 404);
            }
            DB::table('workspaces')->where('id', $workspace)->lockForUpdate()->first();
            app(EntitlementService::class)->assertCapacity($workspace, 'geofences.max', DB::table('geofences')->where('workspace_id', $workspace)->where('active', true)->count());
            foreach ($data['target_user_ids'] as $subject) {
                app(ConsentService::class)->assertVisible($actor, $workspace, $subject);
            }
            $id = (string) Str::uuid();
            DB::insert('INSERT INTO geofences(id,workspace_id,group_id,owner_id,name,center,radius_m,hysteresis_m,dwell_seconds) VALUES (?,?,?,?,?,ST_SetSRID(ST_MakePoint(?,?),4326)::geography,?,?,?)',
                [$id, $workspace, $data['group_id'] ?? null, $actor, $data['name'], $data['longitude'], $data['latitude'], $data['radius_m'], $data['hysteresis_m'] ?? 25, $data['dwell_seconds'] ?? 30]);
            foreach ($data['target_user_ids'] as $subject) {
                DB::table('geofence_targets')->insert(['workspace_id' => $workspace, 'geofence_id' => $id, 'user_id' => $subject]);
            }

            return ['id' => $id];
        }, 3);
    }

    public function process(string $workspace, array $payload): void
    {
        $captured = CarbonImmutable::parse($payload['captured_at']);
        if ($captured->lt(now()->subSeconds(app(LocationSettings::class)->raw()['realtime_max_age_seconds']))) {
            return;
        }
        $zones = DB::table('geofences as g')->join('geofence_targets as t', 't.geofence_id', '=', 'g.id')
            ->where('g.workspace_id', $workspace)->where('g.active', true)->where('t.user_id', $payload['user_id'])->select('g.*')->get();
        foreach ($zones as $zone) {
            try {
                app(ConsentService::class)->assertVisible($zone->owner_id, $workspace, $payload['user_id'], 'current', $payload['captured_at']);
            } catch (ApiException) {
                continue;
            }
            $this->transition($workspace, $zone, $payload, $captured);
        }
    }

    private function transition(string $workspace, object $zone, array $payload, CarbonImmutable $captured): void
    {
        DB::transaction(function () use ($workspace, $zone, $payload, $captured): void {
            $key = ['geofence_id' => $zone->id, 'user_id' => $payload['user_id']];
            DB::table('geofence_states')->insertOrIgnore($key + ['state' => 'unknown']);
            $state = DB::table('geofence_states')->where($key)->lockForUpdate()->first();
            if ($state->last_processed_at && $captured->lte($state->last_processed_at)) {
                return;
            }
            $point = DB::selectOne('SELECT p.accuracy_m, ST_DWithin(p.position,g.center,GREATEST(0,g.radius_m-g.hysteresis_m-p.accuracy_m)) AS inside,
                NOT ST_DWithin(p.position,g.center,g.radius_m+g.hysteresis_m+p.accuracy_m) AS outside
                FROM location_points p CROSS JOIN geofences g WHERE p.id=? AND p.captured_at=? AND p.user_id=? AND g.id=?',
                [$payload['point_id'], $captured, $payload['user_id'], $zone->id]);
            if (! $point) {
                return;
            }
            $candidate = $point->inside ? 'inside' : ($point->outside ? 'outside' : null);
            $update = ['last_processed_at' => $captured];
            if ($candidate === null || $candidate === $state->state) {
                DB::table('geofence_states')->where($key)->update($update + ['candidate_since' => null, 'candidate_state' => null]);

                return;
            }
            if ($state->state === 'unknown') {
                DB::table('geofence_states')->where($key)->update($update + ['state' => $candidate]);

                return; // First reliable observation initializes without a fabricated entry event.
            }
            $since = $state->candidate_state === $candidate ? $state->candidate_since : null;
            if (! $since && $zone->dwell_seconds > 0) {
                DB::table('geofence_states')->where($key)->update($update + ['candidate_state' => $candidate, 'candidate_since' => $captured]);

                return;
            }
            if ($since && $captured->lt(CarbonImmutable::parse($since)->addSeconds($zone->dwell_seconds))) {
                DB::table('geofence_states')->where($key)->update($update);

                return;
            }
            $eventId = (string) Str::uuid();
            DB::table('geofence_states')->where($key)->update($update + ['state' => $candidate, 'candidate_state' => null, 'candidate_since' => null, 'transition_seq' => $state->transition_seq + 1]);
            DB::table('geofence_events')->insert($key + ['id' => $eventId, 'workspace_id' => $workspace, 'transition_seq' => $state->transition_seq + 1,
                'type' => $candidate === 'inside' ? 'enter' : 'exit', 'occurred_at' => $captured, 'point_id' => $payload['point_id']]);
            // Zone ownership alone is insufficient; it was checked against current consent above.
            app(NotificationService::class)->create($zone->owner_id, $workspace, 'geofence.'.$candidate, $eventId, $payload['user_id']);
        }, 3);
    }
}
