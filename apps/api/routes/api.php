<?php

use App\Http\Middleware\AuthenticateDevice;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('health', fn () => ApiResponse::data(['status' => 'ok']))->name('api.health');
    require app_path('Modules/Identity/routes.php');
    require app_path('Modules/Workspaces/public-routes.php');
    require app_path('Modules/Billing/webhooks.php');
    require app_path('Modules/Sharing/public-routes.php');
    Route::middleware(AuthenticateDevice::class)->group(function (): void {
        require app_path('Modules/Workspaces/routes.php');
        require app_path('Modules/Location/routes.php');
        require app_path('Modules/Billing/routes.php');
        require app_path('Modules/Partners/routes.php');
        require app_path('Modules/Geofencing/routes.php');
        require app_path('Modules/Safety/routes.php');
        require app_path('Modules/Sharing/routes.php');
        require app_path('Modules/Notifications/routes.php');
        require app_path('Modules/Privacy/routes.php');
        Route::post('broadcasting/auth', function (Request $request) {
            $request->validate(['socket_id' => 'required|string|regex:/^\d+\.\d+$/', 'channel_name' => 'required|string|max:120']);
            require base_path('routes/channels.php');

            return ApiResponse::data(Broadcast::auth($request));
        })->middleware('throttle:60,1')->name('broadcast.auth');
    });
});
