<?php

declare(strict_types=1);

namespace App\Modules\Access;

use App\Support\ApiException;
use Illuminate\Support\Facades\DB;

final class PermissionService
{
    public function assertMember(string $userId, string $workspaceId): void
    {
        $active = DB::table('workspace_memberships as m')->join('users as u', 'u.id', '=', 'm.user_id')
            ->join('workspaces as w', 'w.id', '=', 'm.workspace_id')
            ->where('m.workspace_id', $workspaceId)->where('m.user_id', $userId)
            ->where('m.status', 'active')->whereNull('m.left_at')->where('u.status', 'active')->where('w.status', 'active')->exists();
        if (! $active) {
            throw new ApiException('NOT_FOUND', 'Workspace unavailable.', 404);
        }
    }

    public function assert(string $userId, string $workspaceId, string $permission, ?string $groupId = null): void
    {
        $this->assertMember($userId, $workspaceId);
        $workspace = DB::table('workspace_role_assignments as a')->join('roles as r', 'r.id', '=', 'a.role_id')
            ->join('role_permissions as rp', 'rp.role_id', '=', 'r.id')->join('permissions as p', 'p.id', '=', 'rp.permission_id')
            ->where('a.workspace_id', $workspaceId)->where('a.user_id', $userId)->where('r.scope', 'workspace')->where('p.key', $permission)->exists();
        $group = $groupId && DB::table('group_role_assignments as a')->join('roles as r', 'r.id', '=', 'a.role_id')
            ->join('role_permissions as rp', 'rp.role_id', '=', 'r.id')->join('permissions as p', 'p.id', '=', 'rp.permission_id')
            ->join('group_memberships as gm', fn ($join) => $join->on('gm.group_id', '=', 'a.group_id')->on('gm.user_id', '=', 'a.user_id'))
            ->where('a.workspace_id', $workspaceId)->where('a.group_id', $groupId)->where('a.user_id', $userId)
            ->whereNull('gm.left_at')->where('r.scope', 'group')->where('p.key', $permission)->exists();
        if (! $workspace && ! $group) {
            throw new ApiException('PERMISSION_DENIED', 'Action is not permitted.');
        }
    }
}
