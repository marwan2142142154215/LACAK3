<?php

use App\Http\Middleware\AuthenticateDeviceCredential;
use App\Http\Middleware\AuthenticateGatewayService;
use App\Http\Middleware\EnsureActiveUser;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // PHP-FPM only accepts traffic from the internal Nginx service, which
        // is the sole HTTP entry point behind the loopback-bound Tunnel.
        $middleware->trustProxies(at: '*');
        $middleware->redirectGuestsTo(static fn (Request $request): ?string => $request->is('api/*') ? null : '/login');
        $middleware->statefulApi();
        $middleware->alias([
            'active.user' => EnsureActiveUser::class,
            'device.auth' => AuthenticateDeviceCredential::class,
            'gateway.service' => AuthenticateGatewayService::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn (Request $request, Throwable $exception): bool => $request->is('api/*') || $request->expectsJson()
        );

        $exceptions->render(function (Throwable $exception, Request $request): ?JsonResponse {
            if (! $request->is('api/*')) {
                return null;
            }

            $status = match (true) {
                $exception instanceof ValidationException => 422,
                $exception instanceof AuthenticationException => 401,
                $exception instanceof AuthorizationException => 403,
                $exception instanceof ModelNotFoundException => 404,
                $exception instanceof HttpExceptionInterface => $exception->getStatusCode(),
                default => 500,
            };
            $message = $status >= 500 ? 'Terjadi kesalahan pada server.' : $exception->getMessage();
            $errors = $exception instanceof ValidationException ? $exception->errors() : (object) [];

            return response()->json([
                'success' => false,
                'message' => $message,
                'errors' => $errors,
            ], $status);
        });
    })->create();
