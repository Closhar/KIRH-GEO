<?php

use App\Modules\Consent\ConsentService;
use App\Modules\Notifications\NotificationService;
use App\Support\ApiException;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

Route::get('notifications', function (Request $request, ConsentService $consents) {
    $items = DB::table('notifications')->where('user_id', $request->user()->id)->orderByDesc('created_at')->limit(100)->get();

    return ApiResponse::data($items->filter(function ($item) {
        try {
            app(NotificationService::class)->assertDeliverable($item);

            return true;
        } catch (ApiException) {
            return false;
        }
    })->map(fn ($item) => ['id' => $item->id, 'type' => $item->type, 'event_id' => $item->event_id, 'created_at' => $item->created_at, 'read_at' => $item->read_at])->values());
});
Route::post('notifications/{notification}/read', function (Request $request, string $notification) {
    DB::table('notifications')->where('id', $notification)->where('user_id', $request->user()->id)->whereNull('read_at')->update(['read_at' => now()]);

    return ApiResponse::data(['read' => true]);
})->whereUuid('notification');
Route::post('device-tokens', function (Request $request) {
    $data = $request->validate(['provider' => 'required|in:fcm,apns', 'token' => 'required|string|max:4096']);
    DB::table('device_tokens')->updateOrInsert(['provider' => $data['provider'], 'token_fingerprint' => hash('sha256', $data['token'])],
        ['id' => (string) Str::uuid(), 'device_id' => $request->attributes->get('device_id'), 'token_encrypted' => Crypt::encryptString($data['token']), 'invalidated_at' => null]);

    return ApiResponse::data(['registered' => true]);
});
