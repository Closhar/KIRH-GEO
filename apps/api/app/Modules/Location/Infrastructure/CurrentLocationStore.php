<?php

declare(strict_types=1);

namespace App\Modules\Location\Infrastructure;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

final class CurrentLocationStore
{
    public function get(string $workspaceId, string $userId, string $deviceId, string $expectedPointId): ?object
    {
        try {
            $value = Redis::hget('geo:current:'.$workspaceId.':'.$userId.':'.$deviceId, 'point');
            $point = $value ? json_decode($value, false, flags: JSON_THROW_ON_ERROR) : null;

            // Caller has just authorized this exact durable point. Cache presence never grants access.
            return $point && ($point->id ?? null) === $expectedPointId && ($point->device_id ?? null) === $deviceId ? $point : null;
        } catch (\Throwable $exception) {
            Log::warning('location.current_cache_unavailable', ['exception_type' => $exception::class]);

            return null;
        }
    }

    public function remember(string $workspaceId, string $userId, array $point, int $ttl): void
    {
        $key = 'geo:current:'.$workspaceId.':'.$userId.':'.$point['device_id'];
        // Timestamp + UUID gives deterministic ordering, even for equal capture times.
        $order = (new \DateTimeImmutable($point['captured_at']))->format('U.u').':'.$point['id'];
        $ttl = min($ttl, max(1, (new \DateTimeImmutable($point['captured_at']))->getTimestamp() + $ttl - time()));
        $script = <<<'LUA'
local previous = redis.call('HGET', KEYS[1], 'order')
if not previous or previous <= ARGV[1] then
  redis.call('HSET', KEYS[1], 'order', ARGV[1], 'point', ARGV[2])
  redis.call('EXPIRE', KEYS[1], ARGV[3])
  return 1
end
return 0
LUA;
        try {
            Redis::eval($script, 1, $key, $order, json_encode($point, JSON_THROW_ON_ERROR), max(1, $ttl));
        } catch (\Throwable $exception) {
            Log::warning('location.current_cache_unavailable', ['exception_type' => $exception::class]);
        }
    }
}
