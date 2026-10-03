<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Hardened response headers for the JSON API.
 *
 * CSP is API-scoped (no HTML served) — `default-src 'none'` plus
 * frame-ancestors 'none' is correct here; the web app sets its own policy.
 * HSTS is emitted only when the app is actually served over HTTPS in
 * production (APP_URL=https://… && FORCE_HSTS=true) — the proxy layer
 * should also set it; this is the app-level fallback.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');
        $response->headers->set(
            'Content-Security-Policy',
            "default-src 'none'; frame-ancestors 'none'; base-uri 'none'"
        );

        if (config('transithub.force_hsts') && str_starts_with((string) config('app.url'), 'https://')) {
            $response->headers->set(
                'Strict-Transport-Security',
                'max-age='.(int) config('transithub.hsts_max_age').'; includeSubDomains'
            );
        }

        return $response;
    }
}
