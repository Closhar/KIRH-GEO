<?php

use App\Modules\Privacy\PrivacyService;
use App\Support\ApiException;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

Route::post('privacy/exports', function (Request $request, PrivacyService $service) {
    return ApiResponse::data($service->requestExport($request->user()->id), 202);
})->middleware('throttle:3,60');
Route::get('privacy/exports/{export}', function (Request $request, string $export) {
    $item = DB::table('export_requests')->where('id', $export)->where('user_id', $request->user()->id)->first();
    if (! $item) {
        throw new ApiException('NOT_FOUND', 'Export unavailable.', 404);
    }

    return ApiResponse::data(['id' => $item->id, 'status' => $item->status, 'expires_at' => $item->expires_at]);
})->whereUuid('export');
Route::get('privacy/exports/{export}/download', function (Request $request, string $export) {
    $item = DB::table('export_requests')->where('id', $export)->where('user_id', $request->user()->id)
        ->where('status', 'completed')->where('expires_at', '>', now())->first();
    if (! $item || ! Storage::disk('local')->exists($item->storage_reference)) {
        throw new ApiException('NOT_FOUND', 'Export unavailable.', 404);
    }

    return Storage::disk('local')->download($item->storage_reference, 'kirh-geo-export.ndjson', ['Cache-Control' => 'no-store', 'Content-Type' => 'application/x-ndjson']);
})->whereUuid('export');
Route::post('privacy/deletion', function (Request $request, PrivacyService $service) {
    $request->validate(['confirmed' => 'required|accepted']);

    return ApiResponse::data($service->requestDeletion($request->user()->id), 202);
})->middleware('throttle:3,60');
