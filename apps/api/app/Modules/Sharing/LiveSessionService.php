<?php

declare(strict_types=1);

namespace App\Modules\Sharing;

use App\Modules\Access\EntitlementService;
use App\Modules\Access\PermissionService;
use App\Modules\Consent\ConsentService;
use App\Modules\Notifications\NotificationService;
use App\Support\ApiException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class LiveSessionService
{
    public function request(string $actor, string $workspace, string $subject, int $minutes): array
    {
        return DB::transaction(function () use ($actor, $workspace, $subject, $minutes): array {
            app(PermissionService::class)->assert($actor, $workspace, 'live.create');
            app(EntitlementService::class)->assert($workspace, 'live.enabled');
            app(ConsentService::class)->assertVisible($actor, $workspace, $subject);
            $id = (string) Str::uuid();
            DB::table('live_sessions')->insert(['id' => $id, 'workspace_id' => $workspace, 'initiator_id' => $actor,
                'expires_at' => now()->addMinutes($minutes), 'status' => 'requested']);
            DB::table('live_session_participants')->insert(['workspace_id' => $workspace, 'session_id' => $id, 'user_id' => $subject]);
            app(NotificationService::class)->create($subject, $workspace, 'live.requested', $id, $subject);

            return ['id' => $id, 'status' => 'requested', 'expires_at' => now()->addMinutes($minutes)->toIso8601String()];
        });
    }

    public function accept(string $subject, string $workspace, string $id): array
    {
        return DB::transaction(function () use ($subject, $workspace, $id): array {
            DB::table('workspaces')->where('id', $workspace)->lockForUpdate()->first();
            $session = DB::table('live_sessions')->where('id', $id)->where('workspace_id', $workspace)->lockForUpdate()->first();
            $participant = DB::table('live_session_participants')->where('session_id', $id)->where('user_id', $subject)->first();
            if (! $session || ! $participant || ! in_array($session->status, ['requested', 'active'], true) || now()->gte($session->expires_at)) {
                throw new ApiException('NOT_FOUND', 'Live request unavailable.', 404);
            }
            app(PermissionService::class)->assert($subject, $workspace, 'location.publish_own');
            app(EntitlementService::class)->assert($workspace, 'live.enabled');
            $grants = app(ConsentService::class)->assertVisible($session->initiator_id, $workspace, $subject);
            if ($participant->accepted_at) {
                return ['id' => $id, 'status' => 'active'];
            }
            $minutes = max(1, (int) ceil(now()->diffInSeconds(CarbonImmutable::parse($session->expires_at)) / 60));
            $featureId = DB::table('features')->where('key', 'live.minutes_per_period')->value('id');
            $period = now()->startOfMonth();
            $key = ['workspace_id' => $workspace, 'feature_id' => $featureId, 'period_start' => $period];
            DB::table('usage_counters')->insertOrIgnore($key + ['period_end' => $period->copy()->addMonth()]);
            $counter = DB::table('usage_counters')->where($key)->lockForUpdate()->first();
            if ($counter->consumed + $counter->reserved + $minutes > app(EntitlementService::class)->resolve($workspace, 'live.minutes_per_period')) {
                throw new ApiException('LIMIT_EXCEEDED', 'Live minutes limit reached.', 409);
            }
            DB::table('usage_counters')->where($key)->increment('reserved', $minutes);
            DB::table('usage_reservations')->insert($key + ['id' => $id, 'quantity' => $minutes, 'expires_at' => $session->expires_at, 'status' => 'reserved', 'idempotency_key' => 'live:'.$id]);
            DB::table('live_sessions')->where('id', $id)->update(['status' => 'active', 'starts_at' => now()]);
            DB::table('live_session_participants')->where('session_id', $id)->where('user_id', $subject)->update(['accepted_at' => now(), 'consent_grant_id' => $grants[0]]);
            app(NotificationService::class)->create($session->initiator_id, $workspace, 'live.accepted', $id, $subject);

            return ['id' => $id, 'status' => 'active'];
        }, 3);
    }

    public function end(?string $actor, string $workspace, string $id): void
    {
        DB::transaction(function () use ($actor, $workspace, $id): void {
            DB::table('workspaces')->where('id', $workspace)->lockForUpdate()->first();
            $session = DB::table('live_sessions')->where('id', $id)->where('workspace_id', $workspace)->lockForUpdate()->first();
            $participant = DB::table('live_session_participants')->where('session_id', $id)->first();
            if (! $session || ($actor !== null && $actor !== $session->initiator_id && $actor !== $participant?->user_id)) {
                throw new ApiException('NOT_FOUND', 'Live session unavailable.', 404);
            }
            if (in_array($session->status, ['ended', 'expired'], true)) {
                return;
            }
            $expired = now()->gte($session->expires_at);
            $end = $expired ? CarbonImmutable::parse($session->expires_at) : CarbonImmutable::now();
            $reservation = DB::table('usage_reservations')->where('id', $id)->where('status', 'reserved')->lockForUpdate()->first();
            if ($reservation) {
                $quantity = min($reservation->quantity, max(1, (int) ceil(CarbonImmutable::parse($session->starts_at)->diffInSeconds($end) / 60)));
                $key = ['workspace_id' => $workspace, 'feature_id' => $reservation->feature_id, 'period_start' => $reservation->period_start];
                DB::table('usage_counters')->where($key)->decrement('reserved', $reservation->quantity);
                DB::table('usage_counters')->where($key)->increment('consumed', $quantity);
                DB::table('usage_events')->insert($key + ['id' => (string) Str::uuid(), 'idempotency_key' => 'live:'.$id, 'quantity' => $quantity]);
                DB::table('usage_reservations')->where('id', $id)->update(['status' => 'consumed']);
            }
            DB::table('live_sessions')->where('id', $id)->update(['status' => $expired ? 'expired' : 'ended']);
            DB::table('live_session_participants')->where('session_id', $id)->whereNull('ended_at')->update(['ended_at' => $end]);
            foreach (array_unique([$session->initiator_id, $participant?->user_id]) as $recipient) {
                if ($recipient) {
                    app(NotificationService::class)->create($recipient, $workspace, 'live.ended', $id, $participant->user_id);
                }
            }
        }, 3);
    }
}
