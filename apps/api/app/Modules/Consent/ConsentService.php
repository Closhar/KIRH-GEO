<?php

declare(strict_types=1);

namespace App\Modules\Consent;

use App\Modules\Access\EntitlementService;
use App\Modules\Access\PermissionService;
use App\Modules\Sharing\LiveSessionService;
use App\Support\ApiException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class ConsentService
{
    public function __construct(private PermissionService $permissions, private EntitlementService $entitlements) {}

    public function grant(string $subject, string $workspaceId, string $deviceId, array $data): array
    {
        return DB::transaction(function () use ($subject, $workspaceId, $deviceId, $data): array {
            $this->permissions->assert($subject, $workspaceId, 'location.publish_own', $data['group_id']);
            $this->entitlements->assert($workspaceId, 'location.enabled');
            DB::table('users')->where('id', $subject)->lockForUpdate()->first();
            if (! DB::table('devices')->where('id', $deviceId)->where('user_id', $subject)->whereNull('revoked_at')->exists()) {
                throw new ApiException('DEVICE_UNAVAILABLE', 'Active device required.', 403);
            }
            foreach ($data['viewer_user_ids'] as $viewer) {
                $this->assertEdge($workspaceId, $data['group_id'], $subject, $viewer);
            }
            $revision = (int) DB::table('location_preferences')->where('user_id', $subject)->value('revision') + 1;
            $logId = (string) Str::uuid();
            $id = (string) Str::uuid();
            DB::table('consent_logs')->insert(['id' => $logId, 'workspace_id' => $workspaceId, 'user_id' => $subject,
                'actor_id' => $subject, 'device_id' => $deviceId, 'purpose' => 'location_sharing', 'action' => 'grant',
                'audience' => json_encode($data['viewer_user_ids']), 'policy_version' => $data['policy_version'], 'consent_version' => $revision]);
            DB::table('sharing_grants')->insert(['id' => $id, 'workspace_id' => $workspaceId, 'user_id' => $subject,
                'device_id' => $deviceId, 'group_id' => $data['group_id'], 'scope' => $data['scope'], 'starts_at' => now(),
                'ends_at' => $data['ends_at'] ?? null, 'consent_version' => $revision, 'consent_log_id' => $logId]);
            foreach ($data['viewer_user_ids'] as $viewer) {
                DB::table('grant_recipients')->insert(['grant_id' => $id, 'workspace_id' => $workspaceId, 'viewer_user_id' => $viewer]);
            }
            DB::table('location_preferences')->updateOrInsert(['user_id' => $subject], ['primary_device_id' => $deviceId,
                'sharing_paused' => false, 'revision' => $revision, 'updated_at' => now()]);
            Log::info('consent.granted', ['grant_id' => $id, 'recipient_count' => count($data['viewer_user_ids'])]);

            return ['id' => $id, 'consent_version' => $revision, 'viewer_user_ids' => $data['viewer_user_ids']];
        }, 3);
    }

    public function revoke(string $subject, string $workspaceId, string $grantId): void
    {
        DB::transaction(function () use ($subject, $workspaceId, $grantId): void {
            DB::table('users')->where('id', $subject)->lockForUpdate()->first();
            $grant = DB::table('sharing_grants')->where('id', $grantId)->where('workspace_id', $workspaceId)->where('user_id', $subject)->lockForUpdate()->first();
            if (! $grant) {
                throw new ApiException('NOT_FOUND', 'Grant unavailable.', 404);
            }
            if ($grant->revoked_at) {
                return;
            }
            DB::table('sharing_grants')->where('id', $grantId)->update(['revoked_at' => now()]);
            foreach (DB::table('live_session_participants as p')->join('live_sessions as s', 's.id', '=', 'p.session_id')
                ->where('p.consent_grant_id', $grantId)->whereIn('s.status', ['requested', 'active'])->select('s.id', 's.workspace_id')->get() as $session) {
                app(LiveSessionService::class)->end($subject, $session->workspace_id, $session->id);
            }
            DB::table('temporary_shares')->where('grant_id', $grantId)->update(['revoked_at' => now()]);
            DB::table('location_preferences')->where('user_id', $subject)->increment('revision');
            $revision = DB::table('location_preferences')->where('user_id', $subject)->value('revision');
            DB::table('consent_logs')->insert(['workspace_id' => $workspaceId, 'user_id' => $subject, 'actor_id' => $subject,
                'device_id' => $grant->device_id, 'purpose' => 'location_sharing', 'action' => 'revoke',
                'audience' => json_encode(DB::table('grant_recipients')->where('grant_id', $grantId)->pluck('viewer_user_id')->all()),
                'policy_version' => '1', 'consent_version' => $revision]);
            DB::table('outbox_events')->insert(['workspace_id' => $workspaceId, 'type' => 'consent.revoked', 'aggregate_id' => $grantId,
                'payload' => json_encode(['user_id' => $subject, 'grant_id' => $grantId, 'revision' => $revision])]);
            Log::info('consent.revoked', ['grant_id' => $grantId]);
        }, 3);
    }

    public function assertVisible(string $viewer, string $workspaceId, string $subject, string $scope = 'current', ?string $capturedAt = null): array
    {
        $permission = $scope === 'history' ? 'location.read_history' : 'location.read_current';
        $this->permissions->assert($viewer, $workspaceId, $permission);
        $this->permissions->assertMember($subject, $workspaceId);
        $this->entitlements->assert($workspaceId, 'location.enabled');
        $preferences = DB::table('location_preferences')->where('user_id', $subject)->first();
        if (! $preferences || $preferences->sharing_paused) {
            throw new ApiException('CONSENT_REQUIRED', 'Location unavailable.');
        }
        $grants = DB::table('sharing_grants as g')->join('grant_recipients as r', 'r.grant_id', '=', 'g.id')
            ->where('g.workspace_id', $workspaceId)->where('g.user_id', $subject)->where('r.viewer_user_id', $viewer)
            ->whereNull('g.revoked_at')->where('g.starts_at', '<=', now())->whereIn('g.scope', [$scope, 'both'])
            ->where(fn ($q) => $q->whereNull('g.ends_at')->orWhere('g.ends_at', '>', now()))->select('g.*')->get();
        $allowed = [];
        foreach ($grants as $grant) {
            if ($grant->device_id && ($grant->device_id !== $preferences->primary_device_id || ! DB::table('devices')->where('id', $grant->device_id)->whereNull('revoked_at')->exists())) {
                continue;
            }
            // Rejoining a workspace must never reactivate a previous membership's grant.
            $joinedAt = DB::table('workspace_memberships')->where('workspace_id', $workspaceId)->where('user_id', $subject)->value('joined_at');
            if (CarbonImmutable::parse($grant->starts_at)->lt($joinedAt)) {
                continue;
            }
            $viewerJoinedAt = DB::table('workspace_memberships')->where('workspace_id', $workspaceId)->where('user_id', $viewer)->value('joined_at');
            $groupJoinedAt = DB::table('group_memberships')->where('group_id', $grant->group_id)->whereIn('user_id', [$subject, $viewer])->max('joined_at');
            if (CarbonImmutable::parse($grant->starts_at)->lt($viewerJoinedAt)
                || ($groupJoinedAt && CarbonImmutable::parse($grant->starts_at)->lt($groupJoinedAt))) {
                continue;
            }
            if ($capturedAt && (CarbonImmutable::parse($capturedAt)->lt($grant->starts_at) || ($grant->ends_at && CarbonImmutable::parse($capturedAt)->gte($grant->ends_at)))) {
                continue;
            }
            try {
                $this->assertEdge($workspaceId, $grant->group_id, $subject, $viewer);
                $allowed[] = $grant->id;
            } catch (ApiException) {
                // Explicitly disabled edge makes this grant ineligible; another grant may authorize the viewer.
            }
        }
        if ($allowed === []) {
            throw new ApiException('CONSENT_REQUIRED', 'Location unavailable.');
        }

        return $allowed;
    }

    public function assertEdge(string $workspaceId, ?string $groupId, string $subject, string $viewer): void
    {
        $this->permissions->assertMember($viewer, $workspaceId);
        foreach ([$subject, $viewer] as $member) {
            if (! DB::table('group_memberships')->where('workspace_id', $workspaceId)->where('group_id', $groupId)
                ->where('user_id', $member)->whereNull('left_at')->exists()) {
                throw new ApiException('CONSENT_REQUIRED', 'Audience is unavailable.');
            }
        }
        if (! DB::table('group_visibility_permissions')->where('workspace_id', $workspaceId)->where('group_id', $groupId)
            ->where('subject_user_id', $subject)->where('viewer_user_id', $viewer)->where('allowed', true)->exists()) {
            throw new ApiException('PERMISSION_DENIED', 'Administrator has not enabled this visibility direction.');
        }
    }

    public function pause(string $subject): void
    {
        DB::transaction(function () use ($subject): void {
            DB::table('users')->where('id', $subject)->lockForUpdate()->first();
            DB::table('location_preferences')->where('user_id', $subject)->update(['sharing_paused' => true, 'updated_at' => now()]);
            foreach (DB::table('sharing_grants')->where('user_id', $subject)->whereNull('revoked_at')->get() as $grant) {
                $this->revoke($subject, $grant->workspace_id, $grant->id);
            }
        }, 3);
    }
}
