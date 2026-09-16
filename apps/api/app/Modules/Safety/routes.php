<?php

use App\Modules\Access\PermissionService;
use App\Modules\Notifications\NotificationService;
use App\Modules\Safety\SafetyService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::prefix('workspaces/{workspace}/sos')->whereUuid('workspace')->group(function () {
    Route::get('', function (Request $request, string $workspace) {
        app(PermissionService::class)->assertMember($request->user()->id, $workspace);
        $events = DB::table('sos_events')->where('workspace_id', $workspace)->orderByDesc('started_at')->limit(100)->get();

        return ApiResponse::data($events->filter(function ($event) use ($request, $workspace) {
            return $event->user_id === $request->user()->id || (DB::table('sos_acknowledgements')
                ->where('sos_event_id', $event->id)->where('user_id', $request->user()->id)->exists()
                && in_array($request->user()->id, app(NotificationService::class)->viewers($workspace, $event->user_id), true));
        })->map(fn ($event) => ['id' => $event->id, 'user_id' => $event->user_id, 'status' => $event->status,
            'started_at' => $event->started_at, 'ended_at' => $event->ended_at,
            'acknowledgements' => DB::table('sos_acknowledgements')->where('sos_event_id', $event->id)
                ->whereNotNull('acknowledged_at')->select('user_id', 'acknowledged_at')->get()])->values());
    });
    Route::post('', function (Request $request, string $workspace, SafetyService $service) {
        $request->merge(['idempotency_key' => $request->header('Idempotency-Key')]);
        $data = $request->validate(['idempotency_key' => 'required|uuid']);

        return ApiResponse::data($service->start($request->user()->id, $workspace, $request->attributes->get('device_id'), $data['idempotency_key']), 201);
    })->middleware('throttle:6,1');
    Route::post('{sos}/acknowledge', function (Request $request, string $workspace, string $sos, SafetyService $service) {
        $service->acknowledge($request->user()->id, $workspace, $sos);

        return ApiResponse::data(['acknowledged' => true]);
    })->whereUuid('sos');
    Route::post('{sos}/end', function (Request $request, string $workspace, string $sos, SafetyService $service) {
        $service->end($request->user()->id, $workspace, $sos);

        return ApiResponse::data(['ended' => true]);
    })->whereUuid('sos');
});
