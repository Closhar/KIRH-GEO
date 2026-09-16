<?php

declare(strict_types=1);

namespace App\Modules\Workspaces;

use App\Modules\Access\EntitlementService;
use App\Modules\Access\PermissionService;
use App\Modules\Consent\ConsentService;
use App\Modules\Identity\Application\SessionService;
use App\Support\ApiException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class WorkspaceService
{
    public function __construct(private PermissionService $permissions, private EntitlementService $entitlements) {}

    public function create(string $userId, string $name): array
    {
        return DB::transaction(function () use ($userId, $name): array {
            $id = (string) Str::uuid();
            DB::table('workspaces')->insert(['id' => $id, 'name' => $name, 'owner_user_id' => $userId, 'billing_owner_user_id' => $userId]);
            $this->join($id, $userId, 'owner');
            $default = DB::table('application_settings')->where('namespace', 'billing')->where('key', 'default_plan_id')->value('value');
            $planId = $default ? json_decode($default, true) : null;
            if (! $planId || ! DB::table('plans')->where('id', $planId)->where('status', 'published')->exists()) {
                throw new ApiException('CATALOG_UNAVAILABLE', 'Default access is not configured.', 503);
            }
            $this->entitlements->grantPlan($id, $planId, 'plan', $planId);
            Log::info('workspace.created', ['workspace_id' => $id]);

            return ['id' => $id, 'name' => $name];
        });
    }

    public function createGroup(string $userId, string $workspaceId, string $name): array
    {
        return DB::transaction(function () use ($userId, $workspaceId, $name): array {
            $this->permissions->assert($userId, $workspaceId, 'groups.manage');
            DB::table('workspaces')->where('id', $workspaceId)->lockForUpdate()->first();
            $this->entitlements->assertCapacity($workspaceId, 'groups.max', DB::table('groups')->where('workspace_id', $workspaceId)->count());
            $id = (string) Str::uuid();
            DB::table('groups')->insert(['id' => $id, 'workspace_id' => $workspaceId, 'name' => $name]);
            DB::table('group_memberships')->insert(['workspace_id' => $workspaceId, 'group_id' => $id, 'user_id' => $userId, 'joined_at' => now()]);

            return ['id' => $id, 'name' => $name, 'workspace_id' => $workspaceId];
        }, 3);
    }

    public function invite(string $userId, string $workspaceId, ?string $groupId): array
    {
        $this->permissions->assert($userId, $workspaceId, 'members.invite', $groupId);
        if ($groupId && ! DB::table('groups')->where('workspace_id', $workspaceId)->where('id', $groupId)->exists()) {
            throw new ApiException('NOT_FOUND', 'Group unavailable.', 404);
        }
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $code = '';
        for ($i = 0; $i < 10; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        $id = (string) Str::uuid();
        $expires = now()->addMinutes(15);
        DB::table('invitations')->insert(['id' => $id, 'workspace_id' => $workspaceId, 'group_id' => $groupId,
            'token_hash' => $this->codeHash($code), 'invited_by' => $userId, 'expires_at' => $expires]);

        return ['id' => $id, 'code' => $code, 'expires_at' => $expires->toIso8601String()];
    }

    public function accept(string $userId, string $code): array
    {
        return DB::transaction(function () use ($userId, $code): array {
            $invitation = DB::table('invitations')->where('token_hash', $this->codeHash($code))->lockForUpdate()->first();
            if (! $invitation || $invitation->accepted_at || $invitation->revoked_at || now()->gte($invitation->expires_at)) {
                throw new ApiException('INVITATION_INVALID', 'Invitation expired or unavailable.', 422);
            }
            $workspaceId = $invitation->workspace_id;
            DB::table('workspaces')->where('id', $workspaceId)->lockForUpdate()->first();
            $this->permissions->assert($invitation->invited_by, $workspaceId, 'members.invite', $invitation->group_id);
            $member = DB::table('workspace_memberships')->where('workspace_id', $workspaceId)->where('user_id', $userId)->first();
            if (! $member || $member->status !== 'active' || $member->left_at) {
                if ($member) {
                    // Removing a viewer narrows an old audience permanently; rejoining cannot restore it.
                    DB::table('grant_recipients')->where('workspace_id', $workspaceId)->where('viewer_user_id', $userId)->delete();
                    foreach (DB::table('sharing_grants')->where('workspace_id', $workspaceId)->where('user_id', $userId)->whereNull('revoked_at')->get() as $oldGrant) {
                        app(ConsentService::class)->revoke($userId, $workspaceId, $oldGrant->id);
                    }
                }
                $this->entitlements->assertCapacity($workspaceId, 'members.max', DB::table('workspace_memberships')->where('workspace_id', $workspaceId)->where('status', 'active')->whereNull('left_at')->count());
                $this->join($workspaceId, $userId, 'member');
            }
            if ($invitation->group_id) {
                DB::table('group_memberships')->updateOrInsert(['workspace_id' => $workspaceId, 'group_id' => $invitation->group_id, 'user_id' => $userId], ['joined_at' => now(), 'left_at' => null]);
                if ($userId !== $invitation->invited_by) {
                    DB::table('group_visibility_permissions')->updateOrInsert(['workspace_id' => $workspaceId, 'group_id' => $invitation->group_id,
                        'subject_user_id' => $userId, 'viewer_user_id' => $invitation->invited_by], ['allowed' => true, 'updated_by' => $invitation->invited_by, 'updated_at' => now()]);
                }
            }
            DB::table('invitations')->where('id', $invitation->id)->update(['accepted_at' => now(), 'accepted_by' => $userId]);

            return ['workspace_id' => $workspaceId, 'group_id' => $invitation->group_id, 'consent_required' => true];
        }, 3);
    }

    public function setVisibility(string $actor, string $workspaceId, string $groupId, array $data): void
    {
        $this->permissions->assert($actor, $workspaceId, 'visibility.manage', $groupId);
        DB::transaction(function () use ($actor, $workspaceId, $groupId, $data): void {
            foreach ([$data['subject_user_id'], $data['viewer_user_id']] as $userId) {
                $this->permissions->assertMember($userId, $workspaceId);
                if (! DB::table('group_memberships')->where('workspace_id', $workspaceId)->where('group_id', $groupId)->where('user_id', $userId)->whereNull('left_at')->exists()) {
                    throw new ApiException('NOT_FOUND', 'Group member unavailable.', 404);
                }
            }
            DB::table('group_visibility_permissions')->updateOrInsert(['workspace_id' => $workspaceId, 'group_id' => $groupId,
                'subject_user_id' => $data['subject_user_id'], 'viewer_user_id' => $data['viewer_user_id']], ['allowed' => $data['allowed'], 'updated_by' => $actor, 'updated_at' => now()]);
            DB::table('audit_logs')->insert(['actor_id' => $actor, 'workspace_id' => $workspaceId, 'action' => 'visibility.updated',
                'target_type' => 'group', 'target_id' => $groupId, 'redacted_changes' => json_encode($data)]);
        });
    }

    private function join(string $workspaceId, string $userId, string $role): void
    {
        DB::table('workspace_memberships')->updateOrInsert(['workspace_id' => $workspaceId, 'user_id' => $userId], ['status' => 'active', 'joined_at' => now(), 'left_at' => null]);
        DB::table('workspace_role_assignments')->insertOrIgnore(['workspace_id' => $workspaceId, 'user_id' => $userId, 'role_id' => DB::table('roles')->where('key', $role)->value('id')]);
    }

    private function codeHash(string $code): string
    {
        return hash_hmac('sha256', strtoupper(trim($code)), config('app.key'));
    }

    public function joinNewIdentity(array $data): array
    {
        return DB::transaction(function () use ($data): array {
            $userId = (string) Str::uuid();
            $deviceId = (string) Str::uuid();
            DB::table('users')->insert(['id' => $userId, 'name' => $data['name']]);
            DB::table('devices')->insert(['id' => $deviceId, 'user_id' => $userId,
                'installation_id' => $data['installation_id'], 'platform' => $data['platform'], 'app_version' => $data['app_version'] ?? null]);
            DB::table('location_preferences')->insert(['user_id' => $userId, 'primary_device_id' => $deviceId, 'sharing_paused' => true]);
            $membership = $this->accept($userId, $data['code']);
            $tokens = app(SessionService::class)->issue($userId, $deviceId);
            Log::info('workspace.invitation_joined', ['workspace_id' => $membership['workspace_id']]);

            return $membership + $tokens + ['user' => ['id' => $userId, 'name' => $data['name']]];
        }, 3);
    }

    public function transferOwnership(string $actor, string $workspaceId, string $newOwner): void
    {
        DB::transaction(function () use ($actor, $workspaceId, $newOwner): void {
            $workspace = DB::table('workspaces')->where('id', $workspaceId)->lockForUpdate()->first();
            if (! $workspace || $workspace->owner_user_id !== $actor || $actor === $newOwner) {
                throw new ApiException('PERMISSION_DENIED', 'Only the current owner can transfer ownership.');
            }
            $this->permissions->assertMember($newOwner, $workspaceId);
            $ownerRole = DB::table('roles')->where('key', 'owner')->where('scope', 'workspace')->value('id');
            $memberRole = DB::table('roles')->where('key', 'member')->where('scope', 'workspace')->value('id');
            DB::table('workspace_role_assignments')->where('workspace_id', $workspaceId)->where('user_id', $actor)->where('role_id', $ownerRole)->delete();
            DB::table('workspace_role_assignments')->insertOrIgnore(['workspace_id' => $workspaceId, 'user_id' => $actor, 'role_id' => $memberRole]);
            DB::table('workspace_role_assignments')->insertOrIgnore(['workspace_id' => $workspaceId, 'user_id' => $newOwner, 'role_id' => $ownerRole]);
            if ($workspace->billing_owner_user_id === $actor) {
                DB::table('workspace_role_assignments')->insertOrIgnore(['workspace_id' => $workspaceId, 'user_id' => $actor,
                    'role_id' => DB::table('roles')->where('key', 'billing_manager')->where('scope', 'workspace')->value('id')]);
            }
            DB::table('workspaces')->where('id', $workspaceId)->update(['owner_user_id' => $newOwner, 'updated_at' => now()]);
            DB::table('audit_logs')->insert(['actor_id' => $actor, 'workspace_id' => $workspaceId, 'action' => 'workspace.ownership_transferred',
                'target_type' => 'workspace', 'target_id' => $workspaceId, 'redacted_changes' => json_encode(['new_owner_id' => $newOwner])]);
        }, 3);
    }
}
