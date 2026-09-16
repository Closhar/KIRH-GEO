<?php

use App\Modules\Access\LocationSettings;
use App\Modules\Location\Application\IngestBatch;
use App\Modules\Location\Application\ReadLocations;
use App\Support\ApiException;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('locations/batches', function (Request $request, IngestBatch $service, LocationSettings $settings) {
    $policy = $settings->raw();
    if (strlen($request->getContent()) > $policy['batch_max_bytes']) {
        throw new ApiException('BATCH_TOO_LARGE', 'Уменьшите размер батча.', 413);
    }
    $data = $request->validate(['device_id' => 'required|uuid', 'client_batch_id' => 'required|uuid',
        'points' => 'required|array|min:1|max:'.$policy['batch_max_points'], 'points.*' => 'required|array']);

    return ApiResponse::data($service->handle($request->user()->id, $request->attributes->get('device_id'), $data));
})->middleware('throttle:120,1')->name('location.ingest');
Route::get('workspaces/{workspace}/locations/current', function (Request $request, string $workspace, ReadLocations $service) {
    return ApiResponse::data($service->current($request->user()->id, $workspace));
})->whereUuid('workspace')->name('location.current');
Route::get('workspaces/{workspace}/members/{user}/locations/history', function (Request $request, string $workspace, string $user, ReadLocations $service) {
    $data = $request->validate(['date' => 'required|date_format:Y-m-d', 'timezone' => 'required|timezone', 'cursor' => 'nullable|string|max:512']);

    return ApiResponse::data($service->history($request->user()->id, $workspace, $user, $data['date'], $data['timezone'], $data['cursor'] ?? null));
})->whereUuid(['workspace', 'user'])->name('location.history');
