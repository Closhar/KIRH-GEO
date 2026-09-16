<?php

declare(strict_types=1);

namespace App\Modules\Notifications;

use App\Support\ApiException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class DeliveryService
{
    public function deliver(int $limit = 100): int
    {
        $count = 0;
        while ($count < $limit) {
            $found = DB::transaction(function (): bool {
                $delivery = DB::table('notification_deliveries')->where('status', 'pending')
                    ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
                    ->orderBy('id')->lock('FOR UPDATE SKIP LOCKED')->first();
                if (! $delivery) {
                    return false;
                }
                $notification = DB::table('notifications')->where('id', $delivery->notification_id)->first();
                try {
                    app(NotificationService::class)->assertDeliverable($notification);
                } catch (ApiException) {
                    DB::table('notification_deliveries')->where('id', $delivery->id)->update(['status' => 'canceled']);

                    return true;
                }
                try {
                    if ($delivery->channel === 'websocket') {
                        if (! config('broadcasting.default') || in_array(config('broadcasting.default'), ['null', 'log'], true)) {
                            throw new \RuntimeException('BROADCAST_NOT_CONFIGURED');
                        }
                        event(new UserEvent($notification->user_id, $notification->type, $notification->event_id));
                    } else {
                        $tokens = DB::table('device_tokens as t')->join('devices as d', 'd.id', '=', 't.device_id')
                            ->where('d.user_id', $notification->user_id)->whereNull('d.revoked_at')->whereNull('t.invalidated_at')->select('t.*')->get();
                        if ($tokens->isEmpty()) {
                            DB::table('notification_deliveries')->where('id', $delivery->id)->update(['status' => 'canceled']);

                            return true;
                        }
                        foreach ($tokens as $token) {
                            app(PushGateway::class)->send($token->provider, Crypt::decryptString($token->token_encrypted), $notification->type, $notification->event_id);
                        }
                    }
                    DB::table('notification_deliveries')->where('id', $delivery->id)->update(['status' => 'delivered', 'delivered_at' => now(), 'attempts' => $delivery->attempts + 1]);
                } catch (\Throwable $exception) {
                    $attempts = $delivery->attempts + 1;
                    DB::table('notification_deliveries')->where('id', $delivery->id)->update(['status' => $attempts >= 8 ? 'failed' : 'pending',
                        'attempts' => $attempts, 'next_attempt_at' => now()->addSeconds(min(3600, 2 ** $attempts * 5))]);
                    Log::warning('notification.delivery_failed', ['delivery_id' => $delivery->id, 'exception_type' => $exception::class]);
                }

                return true;
            });
            if (! $found) {
                break;
            }
            $count++;
        }

        return $count;
    }
}
