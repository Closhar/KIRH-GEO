<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Access\AccessSeeder;
use App\Modules\Consent\ConsentService;
use App\Modules\Workspaces\WorkspaceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

trait GeoFixture
{
    private function geoFixture(): array
    {
        $this->seed(AccessSeeder::class);
        $owner = (string) Str::uuid();
        $subject = (string) Str::uuid();
        $device = (string) Str::uuid();
        DB::table('users')->insert([['id' => $owner, 'name' => 'Viewer'], ['id' => $subject, 'name' => 'Sender']]);
        DB::table('devices')->insert(['id' => $device, 'user_id' => $subject, 'installation_id' => (string) Str::uuid(), 'platform' => 'android']);
        $service = app(WorkspaceService::class);
        $workspace = $service->create($owner, 'Family')['id'];
        $group = $service->createGroup($owner, $workspace, 'Home')['id'];
        $service->accept($subject, $service->invite($owner, $workspace, $group)['code']);
        $grant = app(ConsentService::class)->grant($subject, $workspace, $device,
            ['group_id' => $group, 'viewer_user_ids' => [$owner], 'scope' => 'both', 'policy_version' => '1'])['id'];

        return compact('owner', 'subject', 'device', 'workspace', 'group', 'grant');
    }

    private function geoPoint(array $fixture, float $latitude, float $longitude, \DateTimeInterface $captured, float $accuracy = 5): array
    {
        $id = (string) Str::uuid();
        DB::insert('INSERT INTO location_points(id,device_id,user_id,client_point_id,captured_at,position,accuracy_m,mode,consent_version)
            VALUES (?,?,?,?,?,ST_SetSRID(ST_MakePoint(?,?),4326)::geography,?,?,?)',
            [$id, $fixture['device'], $fixture['subject'], (string) Str::uuid(), $captured, $longitude, $latitude, $accuracy, 'normal', 2]);
        DB::table('location_point_audiences')->insert(['captured_at' => $captured, 'point_id' => $id, 'workspace_id' => $fixture['workspace'],
            'grant_id' => $fixture['grant'], 'expires_at' => now()->addDays(7)]);

        return ['point_id' => $id, 'captured_at' => $captured->format(DATE_ATOM), 'user_id' => $fixture['subject'], 'device_id' => $fixture['device']];
    }
}
