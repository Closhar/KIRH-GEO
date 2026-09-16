<?php

use App\Modules\Access\PermissionService;
use App\Modules\Billing\Application\BillingService;
use App\Support\ApiException;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;

Route::get('billing/catalog', function () {
    $plans = DB::table('plans')->where('status', 'published')->get()->map(function ($plan) {
        $features = DB::table('plan_features as pf')->join('features as f', 'f.id', '=', 'pf.feature_id')->where('pf.plan_id', $plan->id)->get(['f.key', 'pf.value'])->mapWithKeys(fn ($feature) => [$feature->key => json_decode($feature->value, true)]);

        return ['id' => $plan->id, 'name' => $plan->name, 'features' => $features, 'prices' => DB::table('plan_prices')->where('plan_id', $plan->id)->where('active', true)->get(['id', 'interval', 'currency', 'amount_minor'])];
    });

    return ApiResponse::data($plans);
});
Route::prefix('workspaces/{workspaceId}/billing')->group(function () {
    Route::get('subscription', function (Request $request, string $workspaceId, PermissionService $permissions, BillingService $service) {
        $permissions->assert($request->user()->id, $workspaceId, 'billing.manage');
        $subscription = DB::table('subscriptions')->where('workspace_id', $workspaceId)->orderByDesc('created_at')->first();
        if (! $subscription) {
            throw new ApiException('NOT_FOUND', 'Subscription unavailable.', 404);
        }

        return ApiResponse::data($service->subscriptionView($subscription->id));
    });
    foreach (['checkout', 'cancel', 'trial', 'promo'] as $operation) {
        Route::post($operation, function (Request $request, string $workspaceId, BillingService $service) use ($operation) {
            $key = Validator::make(['key' => $request->header('Idempotency-Key')], ['key' => 'required|uuid'])->validate()['key'];
            $userId = $request->user()->id;
            $result = match ($operation) {
                'checkout' => (function () use ($request, $service, $userId, $workspaceId, $key) {
                    $data = $request->validate(['plan_price_id' => 'required|uuid', 'promo_code' => 'prohibited', 'auto_renew' => 'sometimes|boolean']);

                    return $service->checkout($userId, $workspaceId, $key, $data['plan_price_id'], $data['auto_renew'] ?? false);
                })(),
                'cancel' => $service->cancel($userId, $workspaceId, $key),
                'trial' => $service->trial($userId, $workspaceId, $key),
                'promo' => $service->redeemPromo($userId, $workspaceId, $key, $request->validate(['code' => 'required|string|max:64'])['code']),
            };

            return ApiResponse::data($result, $operation === 'checkout' ? 201 : 200);
        })->middleware('throttle:20,1');
    }
    Route::post('restore', function () {
        throw new ApiException('PROVIDER_UNSUPPORTED', 'Store purchase verification is not configured.', 422);
    });
});
