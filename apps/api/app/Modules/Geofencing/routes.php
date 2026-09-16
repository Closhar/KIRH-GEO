<?php

use App\Modules\Access\PermissionService;
use App\Modules\Consent\ConsentService;
use App\Modules\Geofencing\GeofenceService;
use App\Support\ApiException;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::prefix('workspaces/{workspace}')->whereUuid('workspace')->group(function () {
    Route::post('geofences', function (Request $request, string $workspace, GeofenceService $service) {
        $data = $request->validate(['name' => 'required|string|max:120', 'group_id' => 'nullable|uuid', 'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180', 'radius_m' => 'required|integer|between:50,100000',
            'hysteresis_m' => 'sometimes|integer|between:0,500', 'dwell_seconds' => 'sometimes|integer|between:0,600',
            'target_user_ids' => 'required|array|min:1|max:100', 'target_user_ids.*' => 'required|uuid|distinct']);

        return ApiResponse::data($service->create($request->user()->id, $workspace, $data), 201);
    });
    Route::get('geofences', function (Request $request, string $workspace, PermissionService $permissions) {
        $permissions->assert($request->user()->id, $workspace, 'geofence.manage');

        return ApiResponse::data(DB::table('geofences')->where('workspace_id', $workspace)->where('owner_id', $request->user()->id)->where('active', true)
            ->selectRaw('id,name,radius_m,hysteresis_m,dwell_seconds,ST_Y(center::geometry) AS latitude,ST_X(center::geometry) AS longitude')->get());
    });
    Route::get('geofence-events', function (Request $request, string $workspace, PermissionService $permissions) {
        $permissions->assert($request->user()->id, $workspace, 'geofence.manage');
        $events = DB::table('geofence_events as e')->join('geofences as g', 'g.id', '=', 'e.geofence_id')
            ->where('e.workspace_id', $workspace)->where('g.owner_id', $request->user()->id)
            ->select('e.id', 'e.geofence_id', 'e.user_id', 'e.type', 'e.occurred_at')->orderByDesc('e.occurred_at')->limit(100)->get();

        return ApiResponse::data($events->filter(function ($event) use ($request, $workspace) {
            try {
                app(ConsentService::class)->assertVisible($request->user()->id, $workspace, $event->user_id, 'current', $event->occurred_at);

                return true;
            } catch (ApiException) {
                return false;
            }
        })->values());
    });
    Route::delete('geofences/{geofence}', function (Request $request, string $workspace, string $geofence, PermissionService $permissions) {
        $permissions->assert($request->user()->id, $workspace, 'geofence.manage');
        DB::table('geofences')->where('workspace_id', $workspace)->where('id', $geofence)->where('owner_id', $request->user()->id)->update(['active' => false]);

        return ApiResponse::data(['deleted' => true]);
    })->whereUuid('geofence');
});
