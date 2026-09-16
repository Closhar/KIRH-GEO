<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Support\ApiException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class SessionService
{
    public function issue(string $userId, string $deviceId, ?string $familyId = null): array
    {
        $access = bin2hex(random_bytes(32));
        $refresh = bin2hex(random_bytes(32));
        $id = (string) Str::uuid();
        DB::table('auth_sessions')->insert([
            'id' => $id, 'user_id' => $userId, 'device_id' => $deviceId,
            'family_id' => $familyId ?? (string) Str::uuid(),
            'access_token_hash' => hash('sha256', $access),
            'access_expires_at' => now()->addMinutes(15),
            'refresh_token_hash' => hash('sha256', $refresh),
            'expires_at' => now()->addDays(30),
        ]);
        Log::info('identity.session_created', ['session_id' => $id]);

        return ['session_id' => $id, 'device_id' => $deviceId, 'access_token' => $access,
            'refresh_token' => $refresh, 'expires_in' => 900, 'token_type' => 'Bearer'];
    }

    public function refresh(string $token): array
    {
        $hash = hash('sha256', $token);
        // Lock user first so concurrent rotations within a token family serialize.
        $session = DB::table('auth_sessions')->where('refresh_token_hash', $hash)->first();
        if (! $session) {
            throw new ApiException('UNAUTHENTICATED', 'Сессия недействительна.', 401);
        }
        $result = DB::transaction(function () use ($session, $hash): ?array {
            $user = DB::table('users')->where('id', $session->user_id)->lockForUpdate()->first();
            $current = DB::table('auth_sessions')->where('refresh_token_hash', $hash)->lockForUpdate()->first();
            if ($current->revoked_at || $current->rotated_to_id) {
                DB::table('auth_sessions')->where('family_id', $current->family_id)->update(['revoked_at' => now()]);
                Log::warning('identity.refresh_reuse', ['family_id' => $current->family_id]);

                return null; // Commit family revocation before returning an authentication error.
            }
            $activeDevice = DB::table('devices')->where('id', $current->device_id)->whereNull('revoked_at')->exists();
            if ($user->status !== 'active' || ! $activeDevice || now()->gte($current->expires_at)) {
                DB::table('auth_sessions')->where('id', $current->id)->update(['revoked_at' => now()]);

                return null;
            }
            $new = $this->issue($current->user_id, $current->device_id, $current->family_id);
            DB::table('auth_sessions')->where('id', $current->id)->update(['rotated_to_id' => $new['session_id'], 'revoked_at' => now()]);

            return $new;
        }, 3);

        return $result ?? throw new ApiException('UNAUTHENTICATED', 'Войдите заново.', 401);
    }
}
