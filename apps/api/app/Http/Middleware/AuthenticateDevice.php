<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\ApiException;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

final class AuthenticateDevice
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        if (! $token || strlen($token) !== 64) {
            throw new ApiException('UNAUTHENTICATED', 'Требуется вход.', 401);
        }
        $session = DB::table('auth_sessions as s')->join('devices as d', 's.device_id', '=', 'd.id')
            ->where('s.access_token_hash', hash('sha256', $token))->whereNull('s.revoked_at')
            ->whereNull('d.revoked_at')->where('s.access_expires_at', '>', now())
            ->select('s.*')->first();
        $user = $session ? User::find($session->user_id) : null;
        if (! $user || $user->status !== 'active') {
            throw new ApiException('UNAUTHENTICATED', 'Сессия недействительна.', 401);
        }
        $request->setUserResolver(fn () => $user);
        $request->attributes->set('device_id', $session->device_id);
        $request->attributes->set('session_id', $session->id);

        return $next($request);
    }
}
