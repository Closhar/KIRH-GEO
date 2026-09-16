<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Access\AccessSeeder;
use App\Modules\Access\EntitlementService;
use App\Modules\Access\PermissionService;
use App\Modules\Consent\ConsentService;
use App\Modules\Workspaces\WorkspaceService;
use App\Support\ApiException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AccessConsentTest extends TestCase
{
    use DatabaseTransactions;

    private function identity(string $name): array
    {
        $user = (string) Str::uuid();
        $device = (string) Str::uuid();
        DB::table('users')->insert(['id' => $user, 'name' => $name]);
        DB::table('devices')->insert(['id' => $device, 'user_id' => $user, 'installation_id' => (string) Str::uuid(), 'platform' => 'android']);

        return [$user, $device];
    }

    private function group(): array
    {
        $this->seed(AccessSeeder::class);
        [$owner, $ownerDevice] = $this->identity('Owner');
        [$member, $memberDevice] = $this->identity('Member');
        $service = app(WorkspaceService::class);
        $workspace = $service->create($owner, 'Family')['id'];
        $group = $service->createGroup($owner, $workspace, 'Home')['id'];
        $invite = $service->invite($owner, $workspace, $group);
        $stored = DB::table('invitations')->where('id', $invite['id'])->first();
        $this->assertTrue(now()->lt($stored->expires_at), 'Invitation expiry must remain in future: '.now()->toIso8601String().' / '.$stored->expires_at);
        $service->accept($member, $invite['code']);

        return [$owner, $ownerDevice, $member, $memberDevice, $workspace, $group];
    }

    public function test_directional_permission_does_not_substitute_for_subject_consent(): void
    {
        [$owner, , $member, , $workspace] = $this->group();
        $this->expectException(ApiException::class);
        app(ConsentService::class)->assertVisible($owner, $workspace, $member);
    }

    public function test_grant_does_not_imply_reciprocity_and_revocation_takes_effect_immediately(): void
    {
        [$owner, , $member, $device, $workspace, $group] = $this->group();
        $consents = app(ConsentService::class);
        $grant = $consents->grant($member, $workspace, $device, ['group_id' => $group, 'viewer_user_ids' => [$owner], 'scope' => 'both', 'policy_version' => '1']);
        $this->assertSame([$grant['id']], $consents->assertVisible($owner, $workspace, $member));
        try {
            $consents->assertVisible($member, $workspace, $owner);
            $this->fail('Reverse visibility must require owner consent.');
        } catch (ApiException $exception) {
            $this->assertSame('CONSENT_REQUIRED', $exception->errorCode);
        }
        $consents->revoke($member, $workspace, $grant['id']);
        $this->expectException(ApiException::class);
        $consents->assertVisible($owner, $workspace, $member);
    }

    public function test_removing_admin_edge_blocks_an_existing_grant(): void
    {
        [$owner, , $member, $device, $workspace, $group] = $this->group();
        app(ConsentService::class)->grant($member, $workspace, $device, ['group_id' => $group, 'viewer_user_ids' => [$owner], 'scope' => 'current', 'policy_version' => '1']);
        app(WorkspaceService::class)->setVisibility($owner, $workspace, $group, ['subject_user_id' => $member, 'viewer_user_id' => $owner, 'allowed' => false]);
        $this->expectException(ApiException::class);
        app(ConsentService::class)->assertVisible($owner, $workspace, $member);
    }

    public function test_usage_retry_is_idempotent_and_changed_payload_is_rejected(): void
    {
        [, , , , $workspace] = $this->group();
        $service = app(EntitlementService::class);
        $service->consume($workspace, 'live.minutes_per_period', 10, 'operation-one');
        $service->consume($workspace, 'live.minutes_per_period', 10, 'operation-one');
        $this->assertSame(10, (int) DB::table('usage_counters')->where('workspace_id', $workspace)->value('consumed'));
        $this->expectException(ApiException::class);
        $service->consume($workspace, 'live.minutes_per_period', 11, 'operation-one');
    }

    public function test_new_member_cannot_appear_in_existing_grant_audience(): void
    {
        [$owner, , $member, $device, $workspace, $group] = $this->group();
        app(ConsentService::class)->grant($member, $workspace, $device, ['group_id' => $group, 'viewer_user_ids' => [$owner], 'scope' => 'current', 'policy_version' => '1']);
        [$newMember] = $this->identity('Another member');
        $service = app(WorkspaceService::class);
        $service->accept($newMember, $service->invite($owner, $workspace, $group)['code']);
        $service->setVisibility($owner, $workspace, $group, ['subject_user_id' => $member, 'viewer_user_id' => $newMember, 'allowed' => true]);
        $this->expectException(ApiException::class);
        app(ConsentService::class)->assertVisible($newMember, $workspace, $member);
    }

    public function test_new_invitee_gets_own_session_but_no_automatic_consent(): void
    {
        [$owner, , , , $workspace, $group] = $this->group();
        $service = app(WorkspaceService::class);
        $data = ['code' => $service->invite($owner, $workspace, $group)['code'], 'name' => 'Sender',
            'installation_id' => (string) Str::uuid(), 'platform' => 'android'];
        $joined = $service->joinNewIdentity($data);
        $this->assertNotSame($owner, $joined['user']['id']);
        $this->assertTrue($joined['consent_required']);
        $this->assertTrue((bool) DB::table('location_preferences')->where('user_id', $joined['user']['id'])->value('sharing_paused'));
        $this->assertSame(0, DB::table('sharing_grants')->where('user_id', $joined['user']['id'])->count());
        $before = DB::table('users')->count();
        try {
            $service->joinNewIdentity($data);
            $this->fail('An invitation must be single use.');
        } catch (ApiException $exception) {
            $this->assertSame('INVITATION_INVALID', $exception->errorCode);
        }
        $this->assertSame($before, DB::table('users')->count(), 'Failed bootstrap must not leave orphan identities.');
    }

    public function test_owner_can_share_with_member_after_enabling_reverse_direction(): void
    {
        [$owner, $ownerDevice, $member, , $workspace, $group] = $this->group();
        app(WorkspaceService::class)->setVisibility($owner, $workspace, $group, ['subject_user_id' => $owner, 'viewer_user_id' => $member, 'allowed' => true]);
        $grant = app(ConsentService::class)->grant($owner, $workspace, $ownerDevice,
            ['group_id' => $group, 'viewer_user_ids' => [$member], 'scope' => 'current', 'policy_version' => '1']);
        $this->assertSame([$grant['id']], app(ConsentService::class)->assertVisible($member, $workspace, $owner));
        $this->expectException(ApiException::class);
        app(ConsentService::class)->assertVisible($member, $workspace, $owner, 'history');
    }

    public function test_pause_revokes_grants_and_hides_location(): void
    {
        [$owner, , $member, $device, $workspace, $group] = $this->group();
        $consents = app(ConsentService::class);
        $consents->grant($member, $workspace, $device, ['group_id' => $group, 'viewer_user_ids' => [$owner], 'scope' => 'current', 'policy_version' => '1']);
        $consents->pause($member);
        $this->assertSame(0, DB::table('sharing_grants')->where('user_id', $member)->whereNull('revoked_at')->count());
        $this->expectException(ApiException::class);
        $consents->assertVisible($owner, $workspace, $member);
    }

    public function test_viewer_rejoining_does_not_reactivate_old_consent(): void
    {
        [$owner, , $member, $device, $workspace, $group] = $this->group();
        $consents = app(ConsentService::class);
        $consents->grant($member, $workspace, $device, ['group_id' => $group, 'viewer_user_ids' => [$owner], 'scope' => 'current', 'policy_version' => '1']);
        DB::table('workspace_memberships')->where('workspace_id', $workspace)->where('user_id', $owner)->update(['joined_at' => now()->addSecond()]);
        $this->expectException(ApiException::class);
        $consents->assertVisible($owner, $workspace, $member);
    }

    public function test_transferring_ownership_retains_billing_owner_permission_without_location_authority(): void
    {
        [$owner, , $member, , $workspace] = $this->group();
        app(WorkspaceService::class)->transferOwnership($owner, $workspace, $member);
        app(PermissionService::class)->assert($owner, $workspace, 'billing.manage');
        $this->assertSame($owner, DB::table('workspaces')->where('id', $workspace)->value('billing_owner_user_id'));
        $this->assertSame($member, DB::table('workspaces')->where('id', $workspace)->value('owner_user_id'));
        $this->expectException(ApiException::class);
        app(PermissionService::class)->assert($owner, $workspace, 'members.manage');
    }
}
