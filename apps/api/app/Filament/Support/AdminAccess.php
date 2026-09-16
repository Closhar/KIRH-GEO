<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;

final class AdminAccess
{
    public static function allows(?User $user, string $permission): bool
    {
        return $user !== null && $user->status === 'active'
            && DB::table('admin_role_assignments as a')
                ->join('roles as r', 'r.id', '=', 'a.role_id')
                ->join('role_permissions as rp', 'rp.role_id', '=', 'r.id')
                ->join('permissions as p', 'p.id', '=', 'rp.permission_id')
                ->where('a.user_id', $user->id)->where('r.scope', 'admin')
                ->where('p.key', $permission)->exists();
    }

    public static function authorize(string $permission): User
    {
        $user = auth('web')->user();
        abort_unless(self::allows($user, 'admin.access') && self::allows($user, $permission), 403);

        return $user;
    }

    public static function audit(string $action, string $type, ?string $id, string $reason, array $changes = []): void
    {
        DB::table('audit_logs')->insert([
            'actor_id' => auth('web')->id(), 'action' => $action, 'target_type' => $type,
            'target_id' => $id, 'reason' => $reason,
            'redacted_changes' => json_encode($changes, JSON_THROW_ON_ERROR),
        ]);
    }
}
