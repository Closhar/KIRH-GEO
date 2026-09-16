<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Consent\ConsentService;
use App\Modules\Notifications\DeliveryService;
use App\Modules\Notifications\NotificationService;
use App\Modules\Safety\SafetyService;
use App\Modules\Sharing\LiveSessionService;
use App\Modules\Sharing\TemporaryShareService;
use App\Support\ApiException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\GeoFixture;
use Tests\TestCase;

final class SafetySharingTest extends TestCase
{
    use DatabaseTransactions, GeoFixture;

    public function test_sos_retry_has_one_recipient_snapshot_and_one_outbox_event(): void
    {
        $f = $this->geoFixture();
        $service = app(SafetyService::class);
        $key = (string) Str::uuid();
        $first = $service->start($f['subject'], $f['workspace'], $f['device'], $key);
        $retry = $service->start($f['subject'], $f['workspace'], $f['device'], $key);
        $this->assertSame($first['id'], $retry['id']);
        $this->assertSame(1, DB::table('sos_acknowledgements')->where('sos_event_id', $first['id'])->count());
        $this->assertSame(1, DB::table('outbox_events')->where('aggregate_id', $first['id'])->count());
        $service->acknowledge($f['owner'], $f['workspace'], $first['id']);
        $this->assertNotNull(DB::table('sos_acknowledgements')->where('sos_event_id', $first['id'])->value('acknowledged_at'));
    }

    public function test_temporary_share_session_stops_working_after_subject_revokes_grant(): void
    {
        $f = $this->geoFixture();
        $this->geoPoint($f, 55.75, 37.61, now());
        $service = app(TemporaryShareService::class);
        $share = $service->create($f['subject'], $f['workspace'], ['grant_id' => $f['grant'], 'expires_in_minutes' => 60, 'passcode' => '123456']);
        $session = $service->exchange($share['token'], '123456');
        $this->assertEqualsWithDelta(55.75, $service->current($session['access_token'])->latitude, 0.00001);
        $this->assertNotSame($share['token'], DB::table('temporary_shares')->where('id', $share['id'])->value('token_hash'));
        app(ConsentService::class)->revoke($f['subject'], $f['workspace'], $f['grant']);
        $this->expectException(ApiException::class);
        $service->current($session['access_token']);
    }

    public function test_wrong_share_passcode_and_foreign_subject_grant_are_rejected(): void
    {
        $f = $this->geoFixture();
        $service = app(TemporaryShareService::class);
        try {
            $service->create($f['owner'], $f['workspace'], ['grant_id' => $f['grant'], 'expires_in_minutes' => 30]);
            $this->fail('Owner cannot create a share for another subject.');
        } catch (ApiException $exception) {
            $this->assertSame('CONSENT_REQUIRED', $exception->errorCode);
        }
        $share = $service->create($f['subject'], $f['workspace'], ['grant_id' => $f['grant'], 'expires_in_minutes' => 30, 'passcode' => 'secret-pass']);
        $this->expectException(ApiException::class);
        $service->exchange($share['token'], 'incorrect');
    }

    public function test_live_requires_subject_acceptance_reserves_quota_and_settles_once(): void
    {
        $f = $this->geoFixture();
        $service = app(LiveSessionService::class);
        $session = $service->request($f['owner'], $f['workspace'], $f['subject'], 10);
        $notification = DB::table('notifications')->where('user_id', $f['subject'])->where('type', 'live.requested')->first();
        $this->assertNotNull($notification);
        app(NotificationService::class)->assertDeliverable($notification);
        $this->assertNull(DB::table('live_session_participants')->where('session_id', $session['id'])->value('accepted_at'));
        $service->accept($f['subject'], $f['workspace'], $session['id']);
        $service->accept($f['subject'], $f['workspace'], $session['id']);
        $this->assertSame(1, DB::table('usage_reservations')->where('id', $session['id'])->count());
        $service->end($f['subject'], $f['workspace'], $session['id']);
        $service->end($f['subject'], $f['workspace'], $session['id']);
        $this->assertSame(0, (int) DB::table('usage_counters')->where('workspace_id', $f['workspace'])->value('reserved'));
        $this->assertSame(1, DB::table('usage_events')->where('workspace_id', $f['workspace'])->count());
    }

    public function test_delivery_rechecks_consent_and_cancels_without_external_send(): void
    {
        $f = $this->geoFixture();
        app(NotificationService::class)->create($f['owner'], $f['workspace'], 'sos.started', (string) Str::uuid(), $f['subject']);
        app(ConsentService::class)->revoke($f['subject'], $f['workspace'], $f['grant']);
        app(DeliveryService::class)->deliver();
        $this->assertSame(2, DB::table('notification_deliveries')->where('status', 'canceled')->count());
        $this->assertSame(0, DB::table('notification_deliveries')->where('status', 'delivered')->count());
    }

    public function test_revoking_live_consent_ends_session_and_releases_reservation(): void
    {
        $f = $this->geoFixture();
        $service = app(LiveSessionService::class);
        $session = $service->request($f['owner'], $f['workspace'], $f['subject'], 10);
        $service->accept($f['subject'], $f['workspace'], $session['id']);
        app(ConsentService::class)->revoke($f['subject'], $f['workspace'], $f['grant']);
        $this->assertSame('ended', DB::table('live_sessions')->where('id', $session['id'])->value('status'));
        $this->assertSame(0, (int) DB::table('usage_counters')->where('workspace_id', $f['workspace'])->value('reserved'));
    }
}
