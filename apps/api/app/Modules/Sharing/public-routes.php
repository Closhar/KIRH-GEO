<?php

use App\Modules\Sharing\TemporaryShareService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('shares/exchange', function (Request $request, TemporaryShareService $service) {
    $data = $request->validate(['token' => 'required|string|size:64', 'passcode' => 'nullable|string|max:64']);

    return ApiResponse::data($service->exchange($data['token'], $data['passcode'] ?? null))->header('X-Robots-Tag', 'noindex')->header('Referrer-Policy', 'no-referrer');
})->middleware('throttle:10,1');
Route::get('shares/current', function (Request $request, TemporaryShareService $service) {
    return ApiResponse::data($service->current($request->bearerToken() ?? ''))->header('X-Robots-Tag', 'noindex')->header('Referrer-Policy', 'no-referrer');
})->middleware('throttle:60,1');
