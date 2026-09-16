<?php

declare(strict_types=1);

namespace App\Modules\Access;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class AccessSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = ['workspace.read', 'groups.manage', 'members.invite', 'members.manage', 'visibility.manage',
            'location.read_current', 'location.read_history', 'location.publish_own', 'geofence.manage', 'sos.create',
            'live.create', 'sharing.create_temporary', 'billing.manage'];
        foreach ($permissions as $key) {
            DB::table('permissions')->insertOrIgnore(['id' => (string) Str::uuid(), 'key' => $key]);
        }
        foreach (['owner' => $permissions, 'member' => ['workspace.read', 'location.read_current', 'location.read_history', 'location.publish_own', 'sos.create', 'sharing.create_temporary'], 'billing_manager' => ['billing.manage']] as $role => $keys) {
            DB::table('roles')->insertOrIgnore(['id' => (string) Str::uuid(), 'key' => $role, 'scope' => 'workspace']);
            $roleId = DB::table('roles')->where('key', $role)->value('id');
            foreach (DB::table('permissions')->whereIn('key', $keys)->pluck('id') as $permissionId) {
                DB::table('role_permissions')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permissionId]);
            }
        }
        DB::table('roles')->insertOrIgnore(['id' => (string) Str::uuid(), 'key' => 'platform_admin', 'scope' => 'admin']);
        $adminRole = DB::table('roles')->where('key', 'platform_admin')->value('id');
        foreach (['admin.access', 'admin.location.manage', 'admin.plans.manage', 'admin.promos.manage', 'admin.partners.manage', 'admin.support.read'] as $key) {
            DB::table('permissions')->insertOrIgnore(['id' => (string) Str::uuid(), 'key' => $key]);
            DB::table('role_permissions')->insertOrIgnore(['role_id' => $adminRole, 'permission_id' => DB::table('permissions')->where('key', $key)->value('id')]);
        }
        $features = ['members.max' => 5, 'groups.max' => 3, 'devices.max' => 3, 'history.retention_days' => 7,
            'geofences.max' => 5, 'live.enabled' => true, 'live.minutes_per_period' => 60,
            'temporary_shares.max_active' => 3, 'location.enabled' => true, 'sos.enabled' => true];
        foreach (config('location.modes') as $mode => $settings) {
            $features['location.'.$mode.'.min_interval_seconds'] = $settings['capture_seconds'];
        }
        DB::table('plans')->insertOrIgnore(['id' => (string) Str::uuid(), 'code' => 'starter', 'version' => 1,
            'name' => 'Начальный', 'status' => 'published', 'published_at' => now()]);
        $planId = DB::table('plans')->where('code', 'starter')->where('version', 1)->value('id');
        foreach ($features as $key => $value) {
            DB::table('features')->insertOrIgnore(['id' => (string) Str::uuid(), 'key' => $key,
                'value_type' => is_bool($value) ? 'boolean' : 'integer',
                'merge_strategy' => str_ends_with($key, '.min_interval_seconds') ? 'override' : (is_bool($value) ? 'any' : 'max')]);
            DB::table('plan_features')->insertOrIgnore(['plan_id' => $planId, 'feature_id' => DB::table('features')->where('key', $key)->value('id'), 'value' => json_encode($value)]);
        }
        DB::table('application_settings')->insertOrIgnore(['namespace' => 'billing', 'key' => 'default_plan_id', 'value' => json_encode($planId)]);
        DB::table('application_settings')->insertOrIgnore(['namespace' => 'location', 'key' => 'policy', 'value' => json_encode(config('location'))]);
    }
}
