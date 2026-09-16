<?php

declare(strict_types=1);

namespace App\Modules\Access;

use App\Support\ApiException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class EntitlementService
{
    public function resolve(string $workspaceId, string $feature): bool|int
    {
        $definition = DB::table('features')->where('key', $feature)->first();
        if (! $definition) {
            throw new ApiException('UNKNOWN_FEATURE', 'Feature is not registered.', 422);
        }
        $values = DB::table('entitlements')->where('workspace_id', $workspaceId)->where('feature_id', $definition->id)
            ->whereNull('revoked_at')->where('starts_at', '<=', now())
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()))
            ->orderByDesc('priority')->orderByDesc('starts_at')->get()->map(fn ($row) => json_decode($row->value, true));
        if ($values->isEmpty()) {
            return $definition->value_type === 'boolean' ? false : 0;
        }

        return match ($definition->merge_strategy) {
            'any' => $values->containsStrict(true),
            'override' => $definition->value_type === 'boolean' ? (bool) $values->first() : (int) $values->first(),
            default => (int) $values->max(),
        };
    }

    public function assert(string $workspaceId, string $feature): void
    {
        if (! $this->resolve($workspaceId, $feature)) {
            throw new ApiException('ENTITLEMENT_REQUIRED', 'Feature is unavailable.', 403, ['feature' => $feature]);
        }
    }

    /** Caller must create the counted resource in the same transaction. */
    public function assertCapacity(string $workspaceId, string $feature, int $current): void
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Capacity checks require a transaction and workspace lock.');
        }
        DB::table('workspaces')->where('id', $workspaceId)->lockForUpdate()->first();
        if ($current >= (int) $this->resolve($workspaceId, $feature)) {
            throw new ApiException('LIMIT_EXCEEDED', 'Workspace limit reached.', 409, ['feature' => $feature]);
        }
    }

    public function grantPlan(string $workspaceId, string $planId, string $sourceType, string $sourceId, ?string $endsAt = null): void
    {
        foreach (DB::table('plan_features')->where('plan_id', $planId)->get() as $feature) {
            DB::table('entitlements')->updateOrInsert([
                'workspace_id' => $workspaceId, 'feature_id' => $feature->feature_id, 'source_type' => $sourceType, 'source_id' => $sourceId,
            ], ['id' => (string) Str::uuid(), 'value' => $feature->value,
                'priority' => ['plan' => 0, 'subscription' => 100, 'trial' => 200, 'promo' => 300, 'support' => 400][$sourceType],
                'starts_at' => now(), 'ends_at' => $endsAt, 'revoked_at' => null]);
        }
    }

    public function consume(string $workspaceId, string $feature, int $quantity, string $idempotencyKey): void
    {
        if ($quantity < 1) {
            throw new ApiException('INVALID_QUANTITY', 'Quantity must be positive.', 422);
        }
        DB::transaction(function () use ($workspaceId, $feature, $quantity, $idempotencyKey): void {
            DB::table('workspaces')->where('id', $workspaceId)->lockForUpdate()->first();
            $featureId = DB::table('features')->where('key', $feature)->value('id');
            $old = DB::table('usage_events')->where('workspace_id', $workspaceId)->where('idempotency_key', $idempotencyKey)->first();
            if ($old) {
                if ($old->feature_id !== $featureId || (int) $old->quantity !== $quantity) {
                    throw new ApiException('IDEMPOTENCY_CONFLICT', 'Usage key already used for another operation.', 409);
                }

                return;
            }
            $start = now()->startOfMonth();
            $key = ['workspace_id' => $workspaceId, 'feature_id' => $featureId, 'period_start' => $start];
            DB::table('usage_counters')->insertOrIgnore($key + ['period_end' => $start->copy()->addMonth()]);
            $counter = DB::table('usage_counters')->where($key)->lockForUpdate()->first();
            if ($counter->consumed + $counter->reserved + $quantity > $this->resolve($workspaceId, $feature)) {
                throw new ApiException('LIMIT_EXCEEDED', 'Usage limit reached.', 409);
            }
            DB::table('usage_counters')->where($key)->increment('consumed', $quantity);
            DB::table('usage_events')->insert($key + ['id' => (string) Str::uuid(), 'quantity' => $quantity, 'idempotency_key' => $idempotencyKey]);
        }, 3);
    }

    public function revokeSource(string $workspaceId, string $sourceType, string $sourceId): void
    {
        DB::table('entitlements')->where('workspace_id', $workspaceId)->where('source_type', $sourceType)
            ->where('source_id', $sourceId)->whereNull('revoked_at')->update(['revoked_at' => now()]);
    }
}
