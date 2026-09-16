<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Geofencing\GeofenceService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Support\GeoFixture;
use Tests\TestCase;

final class GeofenceTest extends TestCase
{
    use DatabaseTransactions, GeoFixture;

    public function test_initialization_dwell_hysteresis_duplicate_and_out_of_order_points(): void
    {
        $f = $this->geoFixture();
        $service = app(GeofenceService::class);
        $zone = $service->create($f['owner'], $f['workspace'], ['name' => 'Home', 'latitude' => 55.75, 'longitude' => 37.61,
            'radius_m' => 100, 'hysteresis_m' => 25, 'dwell_seconds' => 10, 'target_user_ids' => [$f['subject']]]);
        $start = now()->startOfSecond();
        $first = $this->geoPoint($f, 55.75, 37.61, $start);
        $service->process($f['workspace'], $first);
        $this->assertSame(0, DB::table('geofence_events')->count(), 'First observation does not invent entry.');
        $this->travelTo($start->copy()->addSeconds(5));
        $outside = $this->geoPoint($f, 55.76, 37.61, now());
        $service->process($f['workspace'], $outside);
        $this->assertSame(0, DB::table('geofence_events')->count(), 'Dwell is required.');
        $this->travelTo($start->copy()->addSeconds(16));
        $confirmed = $this->geoPoint($f, 55.76, 37.61, now());
        $service->process($f['workspace'], $confirmed);
        $service->process($f['workspace'], $confirmed);
        $service->process($f['workspace'], $first);
        $this->assertSame(1, DB::table('geofence_events')->where('geofence_id', $zone['id'])->count());
        $this->assertSame('exit', DB::table('geofence_events')->value('type'));
        $this->assertSame('outside', DB::table('geofence_states')->value('state'));
        $this->travelBack();
    }

    public function test_inaccurate_point_does_not_create_false_transition(): void
    {
        $f = $this->geoFixture();
        $service = app(GeofenceService::class);
        $service->create($f['owner'], $f['workspace'], ['name' => 'Home', 'latitude' => 55.75, 'longitude' => 37.61,
            'radius_m' => 100, 'dwell_seconds' => 0, 'target_user_ids' => [$f['subject']]]);
        $first = $this->geoPoint($f, 55.75, 37.61, now());
        $service->process($f['workspace'], $first);
        $this->travel(1)->seconds();
        $uncertain = $this->geoPoint($f, 55.751, 37.61, now(), 200);
        $service->process($f['workspace'], $uncertain);
        $this->assertSame(0, DB::table('geofence_events')->count());
        $this->assertSame('inside', DB::table('geofence_states')->value('state'));
        $this->travelBack();
    }
}
