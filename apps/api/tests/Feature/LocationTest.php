<?php

namespace Tests\Feature;

use App\Modules\Access\AccessSeeder;
use App\Modules\Consent\ConsentService;
use App\Modules\Identity\Application\SessionService;
use App\Modules\Location\Application\ReadLocations;
use App\Modules\Workspaces\WorkspaceService;
use App\Support\ApiException;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class LocationTest extends TestCase
{
    use DatabaseTransactions;

    private function scenario(): array
    {
        $this->seed(AccessSeeder::class);
        $owner = (string) Str::uuid();
        $subject = (string) Str::uuid();
        $device = (string) Str::uuid();
        DB::table('users')->insert([['id' => $owner, 'name' => 'Viewer'], ['id' => $subject, 'name' => 'Sender']]);
        DB::table('devices')->insert(['id' => $device, 'user_id' => $subject, 'installation_id' => (string) Str::uuid(), 'platform' => 'android']);
        $workspaces = app(WorkspaceService::class);
        $workspace = $workspaces->create($owner, 'Family')['id'];
        $group = $workspaces->createGroup($owner, $workspace, 'Home')['id'];
        $workspaces->accept($subject, $workspaces->invite($owner, $workspace, $group)['code']);
        $grant = app(ConsentService::class)->grant($subject, $workspace, $device, ['group_id' => $group, 'viewer_user_ids' => [$owner], 'scope' => 'both', 'policy_version' => '1']);
        $token = app(SessionService::class)->issue($subject, $device)['access_token'];
        $batch = ['device_id' => $device, 'client_batch_id' => (string) Str::uuid(), 'points' => [[
            'client_point_id' => (string) Str::uuid(), 'captured_at' => now()->addSecond()->toIso8601String(),
            'latitude' => 55.75, 'longitude' => 37.61, 'accuracy_m' => 10, 'battery_pct' => 80, 'mode' => 'normal',
            'consent_revision' => $grant['consent_version'],
        ]]];

        return compact('owner', 'subject', 'device', 'workspace', 'group', 'grant', 'token', 'batch');
    }

    public function test_batch_and_point_replays_do_not_duplicate_history_or_outbox(): void
    {
        $s = $this->scenario();
        $first = $this->withToken($s['token'])->postJson('/api/v1/locations/batches', $s['batch'])->assertOk()->json('data');
        $this->assertSame('accepted', $first['results'][0]['status']);
        $this->withToken($s['token'])->postJson('/api/v1/locations/batches', $s['batch'])->assertOk()->assertExactJson(['data' => $first]);
        $s['batch']['client_batch_id'] = (string) Str::uuid();
        $this->withToken($s['token'])->postJson('/api/v1/locations/batches', $s['batch'])->assertOk()->assertJsonPath('data.results.0.status', 'duplicate');
        $this->assertSame(1, DB::table('location_points')->where('device_id', $s['device'])->count());
        $this->assertSame(1, DB::table('outbox_events')->where('workspace_id', $s['workspace'])->where('type', 'location.accepted')->count());
        $s['batch']['points'][0]['latitude'] = 56;
        $this->withToken($s['token'])->postJson('/api/v1/locations/batches', $s['batch'])->assertConflict();
    }

    public function test_revocation_blocks_ingestion_and_history(): void
    {
        $s = $this->scenario();
        $this->withToken($s['token'])->postJson('/api/v1/locations/batches', $s['batch'])->assertOk();
        app(ConsentService::class)->revoke($s['subject'], $s['workspace'], $s['grant']['id']);
        $s['batch']['client_batch_id'] = (string) Str::uuid();
        $this->withToken($s['token'])->postJson('/api/v1/locations/batches', $s['batch'])->assertForbidden();
        $this->expectException(ApiException::class);
        app(ReadLocations::class)->history($s['owner'], $s['workspace'], $s['subject'], now()->format('Y-m-d'), 'UTC');
    }

    public function test_invalid_point_gets_permanent_rejection_and_no_history(): void
    {
        $s = $this->scenario();
        $s['batch']['points'][0]['latitude'] = 91;
        $this->withToken($s['token'])->postJson('/api/v1/locations/batches', $s['batch'])->assertOk()->assertJsonPath('data.results.0.code', 'INVALID_POINT');
        $this->assertSame(0, DB::table('location_points')->where('device_id', $s['device'])->count());
    }

    public function test_device_identity_cannot_be_spoofed(): void
    {
        $s = $this->scenario();
        $s['batch']['device_id'] = (string) Str::uuid();
        $this->withToken($s['token'])->postJson('/api/v1/locations/batches', $s['batch'])->assertForbidden()->assertJsonPath('error.code', 'DEVICE_MISMATCH');
    }

    public function test_sampling_frequency_is_enforced_for_both_time_neighbours(): void
    {
        $s = $this->scenario();
        $base = $s['batch']['points'][0];
        $time = CarbonImmutable::parse($base['captured_at']);
        $s['batch']['points'][0]['captured_at'] = $time->addSeconds(60)->toIso8601String();
        $this->withToken($s['token'])->postJson('/api/v1/locations/batches', $s['batch'])->assertOk()->assertJsonPath('data.results.0.status', 'accepted');
        // Upload earlier coordinates only one second before the already-stored point.
        $s['batch']['client_batch_id'] = (string) Str::uuid();
        $s['batch']['points'][0] = array_replace($base, ['client_point_id' => (string) Str::uuid(), 'captured_at' => $time->addSeconds(59)->toIso8601String()]);
        $this->withToken($s['token'])->postJson('/api/v1/locations/batches', $s['batch'])->assertOk()->assertJsonPath('data.results.0.code', 'SAMPLING_INTERVAL_NOT_REACHED');
        $s['batch']['client_batch_id'] = (string) Str::uuid();
        $s['batch']['points'][0] = array_replace($base, ['client_point_id' => (string) Str::uuid()]);
        $this->withToken($s['token'])->postJson('/api/v1/locations/batches', $s['batch'])->assertOk()->assertJsonPath('data.results.0.status', 'accepted');
        $this->assertSame(2, DB::table('location_points')->where('user_id', $s['subject'])->count());
    }

    public function test_batch_sorting_uses_instants_across_timezone_offsets(): void
    {
        $s = $this->scenario();
        $early = $s['batch']['points'][0];
        $late = array_replace($early, ['client_point_id' => (string) Str::uuid(),
            'captured_at' => CarbonImmutable::parse($early['captured_at'])->addSeconds(30)->utc()->toIso8601String()]);
        $early['captured_at'] = CarbonImmutable::parse($early['captured_at'])->setTimezone('Europe/Moscow')->toIso8601String();
        // Lexical ordering would process the later UTC point before the earlier +03:00 point.
        $s['batch']['points'] = [$late, $early];
        $this->withToken($s['token'])->postJson('/api/v1/locations/batches', $s['batch'])->assertOk()
            ->assertJsonPath('data.results.0.code', 'SAMPLING_INTERVAL_NOT_REACHED')->assertJsonPath('data.results.1.status', 'accepted');
    }
}
