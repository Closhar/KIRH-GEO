<?php

use App\Modules\Workspaces\WorkspaceService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('invitations/join', function (Request $request, WorkspaceService $service) {
    $data = $request->validate(['code' => 'required|string|size:10', 'name' => 'required|string|max:120',
        'installation_id' => 'required|uuid', 'platform' => 'required|in:android,ios', 'app_version' => 'nullable|string|max:40']);

    return ApiResponse::data($service->joinNewIdentity($data), 201);
})->middleware('throttle:10,1');
