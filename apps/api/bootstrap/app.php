<?php

use App\Http\Middleware\RequestContext;
use App\Modules\Billing\Console\ReconcilePayments;
use App\Modules\Billing\Console\RenewSubscriptions;
use App\Support\ApiException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Validation\ValidationException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*');
        $middleware->append(RequestContext::class);
    })
    ->withCommands([
        RenewSubscriptions::class,
        ReconcilePayments::class,
    ])
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn ($request) => $request->is('api/*') || $request->expectsJson());
        $exceptions->render(function (ApiException $exception) {
            return response()->json(['error' => [
                'code' => $exception->errorCode,
                'message' => $exception->getMessage(),
                'details' => $exception->details,
            ]], $exception->status)->header('Cache-Control', 'no-store');
        });
        $exceptions->render(function (ValidationException $exception, $request) {
            if ($request->is('api/*')) {
                return response()->json(['error' => [
                    'code' => 'VALIDATION_FAILED',
                    'message' => 'Проверьте введённые данные.',
                    'details' => $exception->errors(),
                ]], 422);
            }
        });
    })->create();
