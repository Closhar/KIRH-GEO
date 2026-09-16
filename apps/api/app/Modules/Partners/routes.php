<?php

use App\Modules\Partners\Application\PartnerService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;

Route::get('partners/me', function (Request $request, PartnerService $service) {
    return ApiResponse::data($service->summary($request->user()->id));
});
Route::post('workspaces/{workspaceId}/partners/attribution', function (Request $request, string $workspaceId, PartnerService $service) {
    $data = $request->validate(['referral_code' => 'required|string|max:64']);
    $service->attribute($request->user()->id, $workspaceId, $data['referral_code']);

    return response()->noContent();
})->middleware('throttle:10,1');
Route::post('partners/payouts', function (Request $request, PartnerService $service) {
    $key = Validator::make(['key' => $request->header('Idempotency-Key')], ['key' => 'required|uuid'])->validate()['key'];
    $data = $request->validate(['amount_minor' => 'required|integer|min:1|max:1000000000']);

    return ApiResponse::data($service->requestPayout($request->user()->id, $key, (int) $data['amount_minor']), 201);
})->middleware('throttle:10,1');
