<?php

declare(strict_types=1);

namespace App\Modules\Access;

use App\Modules\Location\Domain\EffectiveLocationPolicy;
use Illuminate\Support\Facades\DB;

final class LocationSettings
{
    public function raw(): array
    {
        $value = DB::table('application_settings')->where('namespace', 'location')->where('key', 'policy')->value('value');

        return array_replace_recursive(config('location'), $value ? json_decode($value, true, flags: JSON_THROW_ON_ERROR) : []);
    }

    public function effective(string $workspaceId, string $userId): array
    {
        $entitlements = app(EntitlementService::class);
        $retention = DB::table('location_preferences')->where('user_id', $userId)->value('history_retention_days');
        $values = ['history.retention_days' => $entitlements->resolve($workspaceId, 'history.retention_days')];
        foreach (array_keys($this->raw()['modes']) as $mode) {
            $key = 'location.'.$mode.'.min_interval_seconds';
            $values[$key] = $entitlements->resolve($workspaceId, $key);
        }

        return EffectiveLocationPolicy::resolve($this->raw(), $values, $retention === null ? null : (int) $retention);
    }
}
