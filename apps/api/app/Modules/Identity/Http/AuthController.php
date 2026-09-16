<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http;

use App\Models\User;
use App\Modules\Access\EntitlementService;
use App\Modules\Consent\ConsentService;
use App\Modules\Identity\Application\SessionService;
use App\Support\ApiException;
use App\Support\ApiResponse;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

final class AuthController
{
    public function register(Request $request, SessionService $sessions): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:254'],
            'password' => ['required', 'string', Password::min(12)->letters()->numbers()],
            'installation_id' => ['required', 'uuid'],
            'platform' => ['required', 'in:android,ios,web'],
        ]);
        $email = mb_strtolower(trim($data['email']));
        if (User::whereRaw('lower(email) = ?', [$email])->exists()) {
            throw new ApiException('ACCOUNT_EXISTS', 'Для этого адреса уже существует аккаунт.', 409);
        }
        try {
            $result = DB::transaction(function () use ($data, $email, $sessions): array {
                $user = User::create(['name' => $data['name'], 'email' => $email, 'password' => $data['password']]);
                $deviceId = $this->device($user->id, $data);
                DB::table('location_preferences')->insert(['user_id' => $user->id, 'primary_device_id' => $deviceId]);

                return ['user' => $user, ...$sessions->issue($user->id, $deviceId)];
            });
        } catch (UniqueConstraintViolationException) {
            throw new ApiException('ACCOUNT_EXISTS', 'Для этого адреса уже существует аккаунт.', 409);
        }

        return ApiResponse::data($result, 201);
    }

    public function login(Request $request, SessionService $sessions): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'], 'password' => ['required', 'string', 'max:1024'],
            'installation_id' => ['required', 'uuid'], 'platform' => ['required', 'in:android,ios,web'],
        ]);
        $user = User::whereRaw('lower(email) = ?', [mb_strtolower(trim($data['email']))])->first();
        $valid = Hash::check($data['password'], $user?->password ?? '$2y$12$Wz8EaCeCDpemuo93NvaJL.OKMOJLKKUiNoHWASVKEQc0SxcvTKvcG');
        if (! $user || ! $valid || $user->status !== 'active') {
            throw new ApiException('INVALID_CREDENTIALS', 'Неверный адрес или пароль.', 401);
        }
        $result = DB::transaction(function () use ($user, $data, $sessions): array {
            $current = DB::table('users')->where('id', $user->id)->lockForUpdate()->first();
            if ($current->status !== 'active') {
                throw new ApiException('INVALID_CREDENTIALS', 'Аккаунт недоступен.', 401);
            }
            $deviceId = $this->device($user->id, $data);

            return ['user' => $user, ...$sessions->issue($user->id, $deviceId)];
        });

        return ApiResponse::data($result);
    }

    public function refresh(Request $request, SessionService $sessions): JsonResponse
    {
        $data = $request->validate(['refresh_token' => ['required', 'string', 'size:64']]);

        return ApiResponse::data($sessions->refresh($data['refresh_token']));
    }

    public function me(Request $request): JsonResponse
    {
        return ApiResponse::data(['user' => $request->user(), 'device_id' => $request->attributes->get('device_id')]);
    }

    public function logout(Request $request): JsonResponse
    {
        DB::transaction(function () use ($request): void {
            DB::table('users')->where('id', $request->user()->id)->lockForUpdate()->first();
            $family = DB::table('auth_sessions')->where('id', $request->attributes->get('session_id'))->where('user_id', $request->user()->id)->value('family_id');
            DB::table('auth_sessions')->where('user_id', $request->user()->id)->where('family_id', $family)->update(['revoked_at' => now()]);
        });

        return ApiResponse::data(['revoked' => true]);
    }

    public function devices(Request $request): JsonResponse
    {
        return ApiResponse::data(DB::table('devices')->where('user_id', $request->user()->id)->get());
    }

    public function revokeDevice(Request $request, string $device): JsonResponse
    {
        DB::transaction(function () use ($request, $device): void {
            DB::table('users')->where('id', $request->user()->id)->lockForUpdate()->first();
            $owned = DB::table('devices')->where('id', $device)->where('user_id', $request->user()->id)->lockForUpdate()->first();
            if (! $owned) {
                throw new ApiException('NOT_FOUND', 'Устройство не найдено.', 404);
            }
            DB::table('devices')->where('id', $device)->update(['revoked_at' => now()]);
            DB::table('auth_sessions')->where('device_id', $device)->update(['revoked_at' => now()]);
            DB::table('device_tokens')->where('device_id', $device)->update(['invalidated_at' => now()]);
            foreach (DB::table('sharing_grants')->where('user_id', $request->user()->id)->where('device_id', $device)->whereNull('revoked_at')->get() as $grant) {
                app(ConsentService::class)->revoke($request->user()->id, $grant->workspace_id, $grant->id);
            }
        });

        return ApiResponse::data(['revoked' => true]);
    }

    private function device(string $userId, array $data): string
    {
        $existing = DB::table('devices')->where('user_id', $userId)->where('installation_id', $data['installation_id'])->first();
        if ($existing) {
            if ($existing->revoked_at) {
                throw new ApiException('DEVICE_REVOKED', 'Устройство отозвано. Зарегистрируйте новую установку.', 403);
            }

            return $existing->id;
        }
        $workspaces = DB::table('workspace_memberships as m')->join('workspaces as w', 'w.id', '=', 'm.workspace_id')
            ->where('m.user_id', $userId)->where('m.status', 'active')->whereNull('m.left_at')->where('w.status', 'active')->pluck('w.id');
        $limit = $workspaces->isEmpty() ? 3 : 0;
        foreach ($workspaces as $workspace) {
            $limit = max($limit, (int) app(EntitlementService::class)->resolve($workspace, 'devices.max'));
        }
        if (DB::table('devices')->where('user_id', $userId)->whereNull('revoked_at')->count() >= $limit) {
            throw new ApiException('LIMIT_EXCEEDED', 'Достигнут лимит устройств. Отзовите старое устройство.', 409, ['feature' => 'devices.max']);
        }
        $id = (string) Str::uuid();
        DB::table('devices')->insert(['id' => $id, 'user_id' => $userId, 'installation_id' => $data['installation_id'], 'platform' => $data['platform'], 'last_seen_at' => now()]);

        return $id;
    }
}
