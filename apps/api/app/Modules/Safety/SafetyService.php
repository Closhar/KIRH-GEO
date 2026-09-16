<?php

declare(strict_types=1);

namespace App\Modules\Safety;

use App\Modules\Access\EntitlementService;
use App\Modules\Access\PermissionService;
use App\Modules\Notifications\NotificationService;
use App\Support\ApiException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class SafetyService
{
    public function start(string $subject, string $workspace, string $device, string $key): array
    {
        return DB::transaction(function () use ($subject, $workspace, $device, $key): array {
            app(PermissionService::class)->assert($subject, $workspace, 'sos.create');
            app(EntitlementService::class)->assert($workspace, 'sos.enabled');
            DB::table('users')->where('id', $subject)->lockForUpdate()->first();
            $existing = DB::table('sos_events')->where('workspace_id', $workspace)->where('user_id', $subject)->where('idempotency_key', $key)->first();
            if ($existing) {
                if ($existing->device_id !== $device) {
                    throw new ApiException('IDEMPOTENCY_CONFLICT', 'SOS key belongs to another device.', 409);
                }

                return ['id' => $existing->id, 'status' => $existing->status];
            }
            if (! DB::table('devices')->where('id', $device)->where('user_id', $subject)->whereNull('revoked_at')->exists()) {
                throw new ApiException('DEVICE_UNAVAILABLE', 'Device unavailable.');
            }
            if (DB::table('sos_events')->where('workspace_id', $workspace)->where('user_id', $subject)->where('status', 'active')->exists()) {
                throw new ApiException('SOS_ALREADY_ACTIVE', 'End the current SOS first.', 409);
            }
            $id = (string) Str::uuid();
            DB::table('sos_events')->insert(['id' => $id, 'workspace_id' => $workspace, 'user_id' => $subject, 'device_id' => $device, 'idempotency_key' => $key]);
            $recipients = app(NotificationService::class)->viewers($workspace, $subject);
            foreach ($recipients as $viewer) {
                DB::table('sos_acknowledgements')->insert(['workspace_id' => $workspace, 'sos_event_id' => $id, 'user_id' => $viewer]);
            }
            DB::table('outbox_events')->insert(['workspace_id' => $workspace, 'type' => 'sos.started', 'aggregate_id' => $id,
                'payload' => json_encode(['subject_id' => $subject])]);
            Log::info('sos.started', ['event_id' => $id, 'recipient_count' => count($recipients)]);

            return ['id' => $id, 'status' => 'active', 'recipient_count' => count($recipients)];
        }, 3);
    }

    public function end(string $subject, string $workspace, string $id): void
    {
        $changed = DB::table('sos_events')->where('id', $id)->where('workspace_id', $workspace)->where('user_id', $subject)
            ->where('status', 'active')->update(['status' => 'ended', 'ended_at' => now()]);
        if ($changed) {
            Log::info('sos.ended', ['event_id' => $id]);
        }
    }

    public function acknowledge(string $viewer, string $workspace, string $id): void
    {
        $event = DB::table('sos_events')->where('id', $id)->where('workspace_id', $workspace)->first();
        if (! $event || ! in_array($viewer, app(NotificationService::class)->viewers($workspace, $event->user_id), true)) {
            throw new ApiException('NOT_FOUND', 'SOS unavailable.', 404);
        }
        DB::table('sos_acknowledgements')->where('sos_event_id', $id)->where('user_id', $viewer)->whereNull('acknowledged_at')->update(['acknowledged_at' => now()]);
    }
}
