<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class RequestContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $correlationId = (string) Str::uuid();
        $request->attributes->set('correlation_id', $correlationId);
        Log::withContext(['correlation_id' => $correlationId]);
        $startedAt = microtime(true);
        $response = $next($request);
        // Use route name, never raw URLs, coordinates, request payloads or tokens.
        Log::debug('http.completed', [
            'route' => $request->route()?->getName(),
            'status' => $response->getStatusCode(),
            'duration_ms' => round((microtime(true) - $startedAt) * 1000),
        ]);
        $response->headers->set('X-Request-ID', $correlationId);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        if ($request->is('api/*')) {
            $response->headers->set('Cache-Control', 'no-store');
        }

        return $response;
    }
}
