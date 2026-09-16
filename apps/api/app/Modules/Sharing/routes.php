<?php

use App\Modules\Access\PermissionService;
use App\Modules\Sharing\LiveSessionService;
use App\Modules\Sharing\TemporaryShareService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::prefix('workspaces/{workspace}')->whereUuid('workspace')->group(function () {
    Route::get('temporary-shares', function (Request $request, string $workspace) {
        app(PermissionService::class)->assertMember($request->user()->id, $workspace);

        return ApiResponse::data(DB::table('temporary_shares')->where('workspace_id', $workspace)->where('subject_id', $request->user()->id)
            ->select('id', 'grant_id', 'scope', 'expires_at', 'revoked_at', 'created_at')->orderByDesc('created_at')->limit(100)->get());
    });
    Route::get('live-sessions', function (Request $request, string $workspace) {
        app(PermissionService::class)->assertMember($request->user()->id, $workspace);

        return ApiResponse::data(DB::table('live_sessions as s')->join('live_session_participants as p', 'p.session_id', '=', 's.id')
            ->where('s.workspace_id', $workspace)->where(fn ($q) => $q->where('p.user_id', $request->user()->id)->orWhere('s.initiator_id', $request->user()->id))
            ->select('s.id', 's.initiator_id', 'p.user_id as subject_id', 's.status', 's.starts_at', 's.expires_at', 'p.accepted_at')
            ->orderByDesc('s.starts_at')->limit(100)->get());
    });
    Route::post('temporary-shares', function (Request $request, string $workspace, TemporaryShareService $service) {
        $data = $request->validate(['grant_id' => 'required|uuid', 'expires_in_minutes' => 'required|integer|between:1,1440',
            'passcode' => 'nullable|string|min:6|max:64', 'confirmed' => 'required|accepted']);

        return ApiResponse::data($service->create($request->user()->id, $workspace, $data), 201);
    });
    Route::delete('temporary-shares/{share}', function (Request $request, string $workspace, string $share) {
        DB::table('temporary_shares')->where('workspace_id', $workspace)->where('id', $share)->where('subject_id', $request->user()->id)->update(['revoked_at' => now()]);

        return ApiResponse::data(['revoked' => true]);
    })->whereUuid('share');
    Route::post('live-sessions', function (Request $request, string $workspace, LiveSessionService $service) {
        $data = $request->validate(['subject_id' => 'required|uuid', 'duration_minutes' => 'required|integer|between:1,60']);

        return ApiResponse::data($service->request($request->user()->id, $workspace, $data['subject_id'], $data['duration_minutes']), 201);
    });
    Route::post('live-sessions/{session}/accept', function (Request $request, string $workspace, string $session, LiveSessionService $service) {
        $request->validate(['confirmed' => 'required|accepted']);

        return ApiResponse::data($service->accept($request->user()->id, $workspace, $session));
    })->whereUuid('session');
    Route::delete('live-sessions/{session}', function (Request $request, string $workspace, string $session, LiveSessionService $service) {
        $service->end($request->user()->id, $workspace, $session);

        return ApiResponse::data(['ended' => true]);
    })->whereUuid('session');
});
