<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Geofencing\GeofenceService;
use App\Modules\Notifications\DeliveryService;
use App\Modules\Notifications\NotificationService;
use App\Modules\Notifications\UserEvent;
use App\Modules\Sharing\LiveSessionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class ProcessOutbox extends Command
{
    protected $signature = 'geo:process-outbox {--limit=100}';

    protected $description = 'Process durable geo events and retry notification deliveries';

    public function handle(): int
    {
        $limit = max(1, min(1000, (int) $this->option('limit')));
        for ($i = 0; $i < $limit; $i++) {
            $eventId = null;
            try {
                $found = DB::transaction(function () use (&$eventId): bool {
                    $event = DB::table('outbox_events')->whereNull('processed_at')->where('available_at', '<=', now())
                        ->whereIn('type', ['location.accepted', 'sos.started', 'consent.revoked', 'membership.left'])
                        ->orderBy('created_at')->lock('FOR UPDATE SKIP LOCKED')->first();
                    if (! $event) {
                        return false;
                    }
                    $eventId = $event->id;
                    $payload = json_decode($event->payload, true, flags: JSON_THROW_ON_ERROR);
                    if ($event->type === 'location.accepted') {
                        app(GeofenceService::class)->process($event->workspace_id, $payload);
                        if (config('broadcasting.default') && ! in_array(config('broadcasting.default'), ['log', 'null'], true)) {
                            foreach (app(NotificationService::class)->viewers($event->workspace_id, $payload['user_id']) as $viewer) {
                                event(new UserEvent($viewer, 'location.changed', $event->aggregate_id));
                            }
                        }
                    } elseif ($event->type === 'sos.started') {
                        $viewers = app(NotificationService::class)->viewers($event->workspace_id, $payload['subject_id']);
                        foreach (DB::table('sos_acknowledgements')->where('sos_event_id', $event->aggregate_id)->pluck('user_id') as $viewer) {
                            if (in_array($viewer, $viewers, true)) {
                                app(NotificationService::class)->create($viewer, $event->workspace_id, 'sos.started', $event->aggregate_id, $payload['subject_id']);
                            }
                        }
                    } elseif ($event->type === 'consent.revoked' || $event->type === 'membership.left') {
                        if (config('broadcasting.default') && ! in_array(config('broadcasting.default'), ['log', 'null'], true)) {
                            $recipients = $event->type === 'consent.revoked'
                                ? DB::table('grant_recipients')->where('grant_id', $event->aggregate_id)->pluck('viewer_user_id')->all()
                                : [];
                            $recipients[] = $payload['user_id'];
                            foreach (array_unique($recipients) as $viewer) {
                                event(new UserEvent($viewer, 'access.changed', $event->aggregate_id));
                            }
                        }
                    }
                    DB::table('consumer_receipts')->insertOrIgnore(['consumer' => 'geo-dispatch-v1', 'event_id' => $event->id]);
                    DB::table('outbox_events')->where('id', $event->id)->update(['processed_at' => now(), 'attempts' => $event->attempts + 1]);

                    return true;
                });
                if (! $found) {
                    break;
                }
            } catch (\Throwable $exception) {
                if ($eventId) {
                    DB::table('outbox_events')->where('id', $eventId)->increment('attempts');
                    DB::table('outbox_events')->where('id', $eventId)->update(['available_at' => now()->addMinute(), 'last_error_code' => 'PROCESSING_FAILED']);
                }
                Log::error('outbox.failed', ['event_id' => $eventId, 'exception_type' => $exception::class]);
            }
        }
        foreach (DB::table('live_sessions')->whereIn('status', ['requested', 'active'])->where('expires_at', '<=', now())->limit($limit)->get() as $session) {
            app(LiveSessionService::class)->end(null, $session->workspace_id, $session->id);
        }
        app(DeliveryService::class)->deliver($limit);

        return self::SUCCESS;
    }
}
