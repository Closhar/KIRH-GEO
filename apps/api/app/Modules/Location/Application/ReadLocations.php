<?php

declare(strict_types=1);

namespace App\Modules\Location\Application;

use App\Modules\Access\LocationSettings;
use App\Modules\Access\PermissionService;
use App\Modules\Consent\ConsentService;
use App\Modules\Location\Infrastructure\CurrentLocationStore;
use App\Support\ApiException;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ReadLocations
{
    public function __construct(private ConsentService $consent, private PermissionService $permissions, private LocationSettings $settings, private CurrentLocationStore $current) {}

    public function current(string $viewer, string $workspaceId): array
    {
        $this->permissions->assert($viewer, $workspaceId, 'location.read_current');
        $members = DB::table('workspace_memberships as m')->join('users as u', 'm.user_id', '=', 'u.id')
            ->where('m.workspace_id', $workspaceId)->where('m.status', 'active')->whereNull('m.left_at')->select('u.id', 'u.name')->get();
        $result = [];
        foreach ($members as $member) {
            if ($member->id === $viewer) {
                continue;
            }
            try {
                $grants = $this->consent->assertVisible($viewer, $workspaceId, $member->id);
                $query = $this->query($workspaceId, $member->id, $grants)
                    ->where('p.captured_at', '>', now()->subSeconds($this->settings->raw()['current_ttl_seconds']))
                    ->orderByDesc('p.captured_at')->orderByDesc('p.id');
                $candidate = (clone $query)->select('p.id', 'p.device_id')->first();
                $point = $candidate ? $this->current->get($workspaceId, $member->id, $candidate->device_id, $candidate->id) : null;
                if ($candidate && ! $point) {
                    $point = $query->where('p.id', $candidate->id)->first();
                    if ($point) {
                        $this->current->remember($workspaceId, $member->id, (array) $point, $this->settings->raw()['current_ttl_seconds']);
                    }
                }
                $result[] = ['user_id' => $member->id, 'name' => $member->name, 'status' => ! $point ? 'no_location' : (CarbonImmutable::parse($point->captured_at)->lt(now()->subSeconds($this->settings->raw()['realtime_max_age_seconds'])) ? 'stale' : 'fresh'), 'location' => $point];
            } catch (ApiException) {
                $result[] = ['user_id' => $member->id, 'name' => $member->name, 'status' => 'unavailable', 'location' => null];
            }
        }

        return $result;
    }

    public function history(string $viewer, string $workspaceId, string $subject, string $date, string $timezone, ?string $cursor = null): array
    {
        $grants = $this->consent->assertVisible($viewer, $workspaceId, $subject, 'history');
        $retention = $this->settings->effective($workspaceId, $subject)['history_retention_days'];
        if ($retention < 1) {
            throw new ApiException('ENTITLEMENT_REQUIRED', 'История недоступна.');
        }
        $start = CarbonImmutable::createFromFormat('!Y-m-d', $date, $timezone)->utc();
        $end = $start->setTimezone($timezone)->addDay()->utc();
        $query = $this->query($workspaceId, $subject, $grants)->where('p.captured_at', '>=', $start)
            ->where('p.captured_at', '<', $end)->where('p.captured_at', '>=', now()->subDays($retention));
        if ($cursor) {
            $decoded = json_decode(base64_decode($cursor, true) ?: '', true);
            if (! is_array($decoded) || ! array_is_list($decoded) || count($decoded) !== 2 || ! is_string($decoded[0]) || ! is_string($decoded[1]) || ! Str::isUuid($decoded[1]) || ! strtotime($decoded[0])) {
                throw new ApiException('INVALID_CURSOR', 'Некорректный курсор.', 422);
            }
            $query->whereRaw('(p.captured_at, p.id) > (?, ?::uuid)', $decoded);
        }
        $points = $query->orderBy('p.captured_at')->orderBy('p.id')->limit(1001)->get();
        $hasMore = $points->count() > 1000;
        $points = $points->take(1000)->values();
        $last = $points->last();

        return ['points' => $points, 'next_cursor' => $hasMore ? base64_encode(json_encode([$last->captured_at, $last->id])) : null,
            'date' => $date, 'timezone' => $timezone];
    }

    private function query(string $workspaceId, string $subject, array $grants): Builder
    {
        return DB::table('location_points as p')->join('devices as d', 'd.id', '=', 'p.device_id')
            ->join('location_preferences as pref', 'pref.user_id', '=', 'p.user_id')->whereNull('d.revoked_at')
            ->whereColumn('pref.primary_device_id', 'p.device_id')->where('p.user_id', $subject)
            ->whereExists(function ($query) use ($workspaceId, $grants): void {
                $query->selectRaw('1')->from('location_point_audiences as a')->join('sharing_grants as g', 'g.id', '=', 'a.grant_id')
                    ->whereColumn('a.point_id', 'p.id')->whereColumn('a.captured_at', 'p.captured_at')->where('a.workspace_id', $workspaceId)
                    ->whereIn('a.grant_id', $grants)->where('a.expires_at', '>', now())->whereColumn('p.captured_at', '>=', 'g.starts_at');
            })->selectRaw('p.id, p.device_id, p.captured_at, p.received_at, ST_Y(p.position::geometry) as latitude, ST_X(p.position::geometry) as longitude, p.accuracy_m, p.battery_pct, p.mode');
    }
}
