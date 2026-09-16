<?php

use App\Modules\Access\EntitlementService;
use App\Modules\Access\LocationSettings;
use App\Modules\Access\PermissionService;
use App\Modules\Consent\ConsentService;
use App\Modules\Workspaces\WorkspaceService;
use App\Support\ApiException;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

foreach (['workspaceId', 'groupId', 'grantId'] as $parameter) {
    Route::pattern($parameter, '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}');
}

Route::get('workspaces', function (Request $request) {
    return ApiResponse::data(DB::table('workspaces as w')->join('workspace_memberships as m', 'm.workspace_id', '=', 'w.id')
        ->where('m.user_id', $request->user()->id)->where('m.status', 'active')->whereNull('m.left_at')->select('w.*')->get());
});
Route::post('location/pause', function (Request $request, ConsentService $service) {
    $service->pause($request->user()->id);

    return ApiResponse::data(['sharing_paused' => true, 'new_consent_required' => true]);
});
Route::post('workspaces', function (Request $request, WorkspaceService $service) {
    $data = $request->validate(['name' => 'required|string|max:120']);

    return ApiResponse::data($service->create($request->user()->id, $data['name']), 201);
});
Route::post('invitations/accept', function (Request $request, WorkspaceService $service) {
    $data = $request->validate(['code' => 'required|string|size:10']);

    return ApiResponse::data($service->accept($request->user()->id, $data['code']));
})->middleware('throttle:10,1');
Route::prefix('workspaces/{workspaceId}')->group(function () {
    Route::post('transfer-ownership', function (Request $request, string $workspaceId, WorkspaceService $service) {
        $data = $request->validate(['new_owner_id' => 'required|uuid', 'confirmed' => 'required|accepted']);
        $service->transferOwnership($request->user()->id, $workspaceId, $data['new_owner_id']);

        return ApiResponse::data(['transferred' => true, 'billing_owner_changed' => false]);
    });
    Route::post('leave', function (Request $request, string $workspaceId, PermissionService $permissions, ConsentService $consents) {
        $subject = $request->user()->id;
        $permissions->assertMember($subject, $workspaceId);
        DB::transaction(function () use ($subject, $workspaceId, $consents): void {
            $workspace = DB::table('workspaces')->where('id', $workspaceId)->lockForUpdate()->first();
            if ($workspace->owner_user_id === $subject) {
                throw new ApiException('OWNERSHIP_TRANSFER_REQUIRED', 'Transfer workspace ownership before leaving.', 409);
            }
            foreach (DB::table('sharing_grants')->where('workspace_id', $workspaceId)->where('user_id', $subject)->whereNull('revoked_at')->get() as $grant) {
                $consents->revoke($subject, $workspaceId, $grant->id);
            }
            DB::table('workspace_memberships')->where('workspace_id', $workspaceId)->where('user_id', $subject)->update(['status' => 'left', 'left_at' => now()]);
            DB::table('group_memberships')->where('workspace_id', $workspaceId)->where('user_id', $subject)->update(['left_at' => now()]);
            DB::table('grant_recipients')->where('workspace_id', $workspaceId)->where('viewer_user_id', $subject)->delete();
            DB::table('workspace_role_assignments')->where('workspace_id', $workspaceId)->where('user_id', $subject)->delete();
            DB::table('group_role_assignments')->where('workspace_id', $workspaceId)->where('user_id', $subject)->delete();
            DB::table('outbox_events')->insert(['workspace_id' => $workspaceId, 'type' => 'membership.left', 'aggregate_id' => $subject,
                'payload' => json_encode(['user_id' => $subject])]);
        }, 3);

        return ApiResponse::data(['left' => true]);
    });
    Route::get('members', function (Request $request, string $workspaceId, PermissionService $permissions) {
        $permissions->assert($request->user()->id, $workspaceId, 'workspace.read');

        return ApiResponse::data(DB::table('workspace_memberships as m')->join('users as u', 'u.id', '=', 'm.user_id')
            ->where('m.workspace_id', $workspaceId)->where('m.status', 'active')->whereNull('m.left_at')->select('u.id', 'u.name', 'm.joined_at')->get());
    });
    Route::get('groups', function (Request $request, string $workspaceId, PermissionService $permissions) {
        $permissions->assert($request->user()->id, $workspaceId, 'workspace.read');

        return ApiResponse::data(DB::table('groups')->where('workspace_id', $workspaceId)->get());
    });
    Route::post('groups', function (Request $request, string $workspaceId, WorkspaceService $service) {
        $data = $request->validate(['name' => 'required|string|max:120']);

        return ApiResponse::data($service->createGroup($request->user()->id, $workspaceId, $data['name']), 201);
    });
    Route::post('invitations', function (Request $request, string $workspaceId, WorkspaceService $service) {
        $data = $request->validate(['group_id' => 'required|uuid']);

        return ApiResponse::data($service->invite($request->user()->id, $workspaceId, $data['group_id']), 201);
    })->middleware('throttle:10,1');
    Route::put('groups/{groupId}/visibility', function (Request $request, string $workspaceId, string $groupId, WorkspaceService $service) {
        $data = $request->validate(['subject_user_id' => 'required|uuid|different:viewer_user_id', 'viewer_user_id' => 'required|uuid', 'allowed' => 'required|boolean']);
        $service->setVisibility($request->user()->id, $workspaceId, $groupId, $data);

        return ApiResponse::data(['updated' => true, 'consent_required' => true]);
    });
    Route::post('sharing-grants', function (Request $request, string $workspaceId, ConsentService $service) {
        $data = $request->validate(['group_id' => 'required|uuid', 'viewer_user_ids' => 'required|array|min:1|max:100',
            'viewer_user_ids.*' => 'required|uuid|distinct', 'scope' => 'required|in:current,history,both',
            'policy_version' => 'required|in:1', 'ends_at' => 'nullable|date|after:now', 'confirmed' => 'required|accepted']);

        return ApiResponse::data($service->grant($request->user()->id, $workspaceId, $request->attributes->get('device_id'), $data), 201);
    });
    Route::get('sharing-grants', function (Request $request, string $workspaceId, PermissionService $permissions) {
        $permissions->assertMember($request->user()->id, $workspaceId);
        $grants = DB::table('sharing_grants')->where('workspace_id', $workspaceId)->where('user_id', $request->user()->id)->get();

        return ApiResponse::data($grants->map(function ($grant) {
            $grant->viewer_user_ids = DB::table('grant_recipients')->where('grant_id', $grant->id)->pluck('viewer_user_id');

            return $grant;
        }));
    });
    Route::delete('sharing-grants/{grantId}', function (Request $request, string $workspaceId, string $grantId, ConsentService $service) {
        $service->revoke($request->user()->id, $workspaceId, $grantId);

        return ApiResponse::data(['revoked' => true]);
    });
    Route::get('effective-entitlements', function (Request $request, string $workspaceId, PermissionService $permissions, EntitlementService $entitlements) {
        $permissions->assertMember($request->user()->id, $workspaceId);

        return ApiResponse::data(DB::table('features')->pluck('key')->mapWithKeys(fn ($key) => [$key => $entitlements->resolve($workspaceId, $key)]));
    });
    Route::get('location-policy', function (Request $request, string $workspaceId, PermissionService $permissions, LocationSettings $settings) {
        $permissions->assertMember($request->user()->id, $workspaceId);

        return ApiResponse::data($settings->effective($workspaceId, $request->user()->id));
    });
});
