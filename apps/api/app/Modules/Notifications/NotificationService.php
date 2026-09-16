<?php

declare(strict_types=1);

namespace App\Modules\Notifications;

use App\Modules\Access\EntitlementService;
use App\Modules\Access\PermissionService;
use App\Modules\Consent\ConsentService;
use App\Support\ApiException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class NotificationService
{
    public function assertDeliverable(object $notification): void
    {
        if (str_starts_with($notification->type, 'live.')) {
            app(PermissionService::class)->assertMember($notification->user_id, $notification->workspace_id);
            app(EntitlementService::class)->assert($notification->workspace_id, 'live.enabled');
            $session = DB::table('live_sessions as s')->join('live_session_participants as p', 'p.session_id', '=', 's.id')
                ->where('s.id', $notification->event_id)->where('s.workspace_id', $notification->workspace_id)
                ->where(fn ($q) => $q->where('s.initiator_id', $notification->user_id)->orWhere('p.user_id', $notification->user_id))->exists();
            if (! $session) {
                throw new ApiException('NOT_FOUND', 'Live event unavailable.', 404);
            }

            return; // Own request/accept/end metadata does not disclose a position or imply reciprocal tracking.
        }
        $payload = json_decode($notification->payload, true);
        app(ConsentService::class)->assertVisible($notification->user_id, $notification->workspace_id, $payload['subject_id']);
    }

    /** Re-evaluated both when creating and delivering sensitive event notifications. */
    public function viewers(string $workspaceId, string $subject): array
    {
        $viewers = [];
        foreach (DB::table('workspace_memberships')->where('workspace_id', $workspaceId)->where('status', 'active')->whereNull('left_at')->pluck('user_id') as $viewer) {
            if ($viewer === $subject) {
                continue;
            }
            try {
                app(ConsentService::class)->assertVisible($viewer, $workspaceId, $subject);
                $viewers[] = $viewer;
            } catch (ApiException) {
                // Recipient no longer has a permitted view; do not disclose even event metadata.
            }
        }

        return $viewers;
    }

    public function create(string $viewer, string $workspaceId, string $type, string $eventId, string $subject): void
    {
        DB::table('notifications')->insertOrIgnore(['id' => (string) Str::uuid(), 'user_id' => $viewer, 'workspace_id' => $workspaceId,
            'type' => $type, 'event_id' => $eventId, 'payload' => json_encode(['subject_id' => $subject])]);
        $id = DB::table('notifications')->where('user_id', $viewer)->where('type', $type)->where('event_id', $eventId)->value('id');
        foreach (['websocket', 'push'] as $channel) {
            $enabled = DB::table('notification_preferences')->where('user_id', $viewer)->where('type', $type)->where('channel', $channel)
                ->where(fn ($q) => $q->whereNull('workspace_id')->orWhere('workspace_id', $workspaceId))->orderByRaw('workspace_id IS NULL')->value('enabled');
            if ($enabled === false) {
                continue;
            }
            DB::table('notification_deliveries')->insertOrIgnore(['id' => (string) Str::uuid(), 'notification_id' => $id,
                'channel' => $channel, 'dedup_key' => $id.':'.$channel]);
        }
    }
}
