<?php

use App\Modules\Billing\Application\BillingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('webhooks/payments/{provider}', function (Request $request, string $provider, BillingService $service) {
    $service->webhook($provider, $request->getContent(), $request->headers->all());

    return response()->noContent(200);
})->where('provider', 'sandbox|yookassa')->middleware('throttle:120,1');
