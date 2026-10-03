<?php

use App\Http\Middleware\EnsurePlatformAdmin;
use App\Http\Middleware\Idempotency;
use App\Http\Middleware\ResolvePublicTenant;
use App\Http\Middleware\ResolveTenant;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Auth\Middleware\Authorize;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\HandlePrecognitiveRequests;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(append: [
            SecurityHeaders::class,
        ]);

        $middleware->alias([
            'tenant' => ResolveTenant::class,
            'public.tenant' => ResolvePublicTenant::class,
            'idempotent' => Idempotency::class,
            'platform' => EnsurePlatformAdmin::class,
        ]);

        $middleware->throttleApi('60,1');

        // Tenant context MUST be bound before route-model substitution —
        // otherwise {trip}/{booking} resolve unscoped and can leak across
        // tenants. This ordering is a security control.
        $middleware->priority([
            HandlePrecognitiveRequests::class,
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
            ShareErrorsFromSession::class,
            ValidateCsrfToken::class,
            AuthenticatesRequests::class,
            ResolveTenant::class,
            ResolvePublicTenant::class,
            SubstituteBindings::class,
            Authorize::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Never leak internals in production responses.
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            $status = $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 500;

            if ($status === 500 && ! config('app.debug')) {
                report($e);

                return response()->json(['message' => 'An unexpected error occurred.'], 500);
            }

            return null;
        });
    })->create();
