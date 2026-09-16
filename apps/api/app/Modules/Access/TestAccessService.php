<?php

declare(strict_types=1);

namespace App\Modules\Access;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class TestAccessService
{
    private const NAMESPACE = 'billing';
    private const ENABLED_KEY = 'test_access_enabled';
    private const LIMITS_KEY = 'test_access_limits';
    private const SOURCE_TYPE = 'test';
    private const SOURCE_ID = '00000000-0000-0000-0000-000000000099';
    private const PRIORITY = 500;

    public function enabled(): bool
    {
        $value = DB::table('application_settings')->where('namespace', self::NAMESPACE)->where('key', self::ENABLED_KEY)->value('value');

        return $value !== null && json_decode($value, true) === true;
    }

    public function limits(): array
    {
        $saved = DB::table('application_settings')->where('namespace', self::NAMESPACE)->where('key', self::LIMITS_KEY)->value('value');
        $saved = $saved ? json_decode($saved, true) : [];

        return is_array($saved) ? $saved : [];
    }

    public function save(bool $enabled, array $features, string $reason): void
    {
        Validator::make(['reason' => trim($reason)], ['reason' => 'required|string|min:5|max:500'])->validate();
        $clean = $this->validateFeatures($features);

        DB::transaction(function () use ($enabled, $clean, $reason): void {
            $version = (int) DB::table('application_settings')->where('namespace', self::NAMESPACE)->where('key', self::LIMITS_KEY)->value('version');
            DB::table('application_settings')->updateOrInsert(['namespace' => self::NAMESPACE, 'key' => self::ENABLED_KEY], [
                'value' => json_encode($enabled), 'version' => $version + 1, 'updated_by' => auth('web')->id(), 'updated_at' => now(),
            ]);
            DB::table('application_settings')->updateOrInsert(['namespace' => self::NAMESPACE, 'key' => self::LIMITS_KEY], [
                'value' => json_encode($clean, JSON_THROW_ON_ERROR), 'version' => $version + 1, 'updated_by' => auth('web')->id(), 'updated_at' => now(),
            ]);
            \App\Filament\Support\AdminAccess::audit(
                $enabled ? 'billing.test_access.enabled' : 'billing.test_access.disabled',
                'application_settings',
                null,
                $reason,
                ['enabled' => $enabled, 'version' => $version + 1],
            );

            if ($enabled) {
                $this->applyToAllActiveWorkspaces($clean);
            } else {
                $this->revokeAll();
            }
        });
    }

    public function grantWorkspace(string $workspaceId): void
    {
        if (! $this->enabled()) {
            return;
        }
        $this->applyToWorkspace($workspaceId, $this->limits());
    }

    public function revokeAll(): void
    {
        DB::table('entitlements')->where('source_type', self::SOURCE_TYPE)->where('source_id', self::SOURCE_ID)->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
    }

    private function applyToAllActiveWorkspaces(array $features): void
    {
        DB::table('workspaces')->where('status', 'active')->pluck('id')->each(function (string $workspaceId) use ($features): void {
            $this->applyToWorkspace($workspaceId, $features);
        });
    }

    private function applyToWorkspace(string $workspaceId, array $features): void
    {
        foreach ($features as $featureId => $value) {
            DB::table('entitlements')->updateOrInsert([
                'workspace_id' => $workspaceId,
                'feature_id' => $featureId,
                'source_type' => self::SOURCE_TYPE,
                'source_id' => self::SOURCE_ID,
            ], [
                'id' => (string) Str::uuid(),
                'value' => json_encode($value),
                'priority' => self::PRIORITY,
                'starts_at' => now(),
                'ends_at' => null,
                'revoked_at' => null,
            ]);
        }
    }

    private function validateFeatures(array $features): array
    {
        $definitions = DB::table('features')->get();
        $clean = [];
        foreach ($definitions as $feature) {
            $value = $features[$feature->id] ?? null;
            Validator::make(['value' => $value], ['value' => $feature->value_type === 'boolean' ? 'required|boolean' : 'required|integer|min:0|max:1000000'])->validate();
            $clean[$feature->id] = $feature->value_type === 'boolean' ? (bool) $value : (int) $value;
        }

        return $clean;
    }
}
