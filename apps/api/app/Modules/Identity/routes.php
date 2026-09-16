<?php

use App\Http\Middleware\AuthenticateDevice;
use App\Modules\Identity\Application\IdentityRecovery;
use App\Modules\Identity\Http\AuthController;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\Rules\Password;

Route::middleware('throttle:10,1')->group(function (): void {
    Route::post('auth/register', [AuthController::class, 'register'])->name('auth.register');
    Route::post('auth/login', [AuthController::class, 'login'])->name('auth.login');
    Route::post('auth/refresh', [AuthController::class, 'refresh'])->name('auth.refresh');
});
Route::middleware('throttle:5,1')->group(function (): void {
    Route::post('auth/forgot-password', function (Request $request, IdentityRecovery $service) {
        $data = $request->validate(['email' => 'required|email|max:254']);
        $service->request($data['email'], 'reset_password');

        return ApiResponse::data(['accepted' => true, 'message' => 'Если адрес зарегистрирован, инструкция будет отправлена.'], 202);
    });
    Route::post('auth/reset-password', function (Request $request, IdentityRecovery $service) {
        $data = $request->validate(['token' => 'required|string|size:64', 'password' => ['required', 'string', 'max:1024', 'confirmed', Password::min(12)->letters()->numbers()]]);
        $service->consume($data['token'], 'reset_password', $data['password']);

        return ApiResponse::data(['reset' => true, 'login_required' => true, 'new_consent_required' => true]);
    });
    Route::post('auth/email/verify', function (Request $request, IdentityRecovery $service) {
        $data = $request->validate(['token' => 'required|string|size:64']);
        $service->consume($data['token'], 'verify_email');

        return ApiResponse::data(['verified' => true]);
    });
});
Route::middleware(AuthenticateDevice::class)->group(function (): void {
    Route::post('auth/email/verification', function (Request $request, IdentityRecovery $service) {
        $service->request($request->user()->email ?? '', 'verify_email');

        return ApiResponse::data(['accepted' => true], 202);
    })->middleware('throttle:3,60');
    Route::get('auth/me', [AuthController::class, 'me'])->name('auth.me');
    Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
    Route::get('devices', [AuthController::class, 'devices'])->name('devices.index');
    Route::delete('devices/{device}', [AuthController::class, 'revokeDevice'])->whereUuid('device')->name('devices.revoke');
});
