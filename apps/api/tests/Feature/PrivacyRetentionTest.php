<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Privacy\PrivacyService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Tests\Support\GeoFixture;
use Tests\TestCase;

final class PrivacyRetentionTest extends TestCase
{
    use DatabaseTransactions, GeoFixture;

    public function test_deletion_stops_access_immediately_then_wipes_points_and_keeps_audit_exception(): void
    {
        Storage::fake('local');
        Redis::shouldReceive('del')->andReturn(1);
        $f = $this->geoFixture();
        $this->geoPoint($f, 55.75, 37.61, now());
        $service = app(PrivacyService::class);
        $result = $service->requestDeletion($f['subject']);
        $this->assertSame('deletion_pending', DB::table('users')->where('id', $f['subject'])->value('status'));
        $this->assertNotNull(DB::table('sharing_grants')->where('id', $f['grant'])->value('revoked_at'));
        $service->erase($f['subject']);
        $this->assertSame(0, DB::table('location_points')->where('user_id', $f['subject'])->count());
        $this->assertSame('blocked', DB::table('users')->where('id', $f['subject'])->value('status'));
        $this->assertTrue(DB::table('deletion_tombstones')->where('subject_hash', $service->subjectHash($f['subject']))->exists());
        $this->assertContains('consent_audit', $result['retained_categories']);
        $this->assertGreaterThan(0, DB::table('consent_logs')->where('user_id', $f['subject'])->count());
    }

    public function test_export_contains_own_points_without_tokens_or_other_profiles(): void
    {
        Storage::fake('local');
        $f = $this->geoFixture();
        $this->geoPoint($f, 55.75, 37.61, now());
        $service = app(PrivacyService::class);
        $result = $service->requestExport($f['subject']);
        $request = DB::table('export_requests')->where('id', $result['id'])->first();
        $service->export($request);
        $completed = DB::table('export_requests')->where('id', $request->id)->first();
        $contents = Storage::disk('local')->get($completed->storage_reference);
        $this->assertSame('completed', $completed->status);
        $this->assertStringContainsString('location_point', $contents);
        $this->assertStringContainsString('55.75', $contents);
        $this->assertStringNotContainsString('refresh_token', $contents);
        $this->assertStringNotContainsString('Viewer', $contents);
    }

    public function test_lower_retention_removes_old_audience_and_point_without_resurrecting(): void
    {
        $f = $this->geoFixture();
        $point = $this->geoPoint($f, 55.75, 37.61, now()->subDays(3));
        $policy = config('location');
        $policy['history_max_days'] = 1;
        $policy['current_ttl_seconds'] = 60;
        DB::table('application_settings')->where('namespace', 'location')->where('key', 'policy')->update(['value' => json_encode($policy)]);
        $this->artisan('geo:maintain-locations')->assertSuccessful();
        $this->assertFalse(DB::table('location_points')->where('id', $point['point_id'])->exists());
        $this->assertFalse(DB::table('location_point_audiences')->where('point_id', $point['point_id'])->exists());
        $policy['history_max_days'] = 90;
        DB::table('application_settings')->where('namespace', 'location')->where('key', 'policy')->update(['value' => json_encode($policy)]);
        $this->artisan('geo:maintain-locations')->assertSuccessful();
        $this->assertFalse(DB::table('location_points')->where('id', $point['point_id'])->exists());
    }
}
