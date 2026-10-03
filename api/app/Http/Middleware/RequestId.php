<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Correlation IDs on every request. Inbound X-Request-ID is honoured when it
 * looks sane (bounded length); otherwise we mint one. The ID is echoed back,
 * stashed on request attributes (Audit reads it), and pushed into the log
 * context so booking→payment→webhook→ticket can be traced end-to-end.
 */
class RequestId
{
    public const HEADER = 'X-Request-ID';

    public function handle(Request $request, Closure $next): Response
    {
        $incoming = (string) $request->headers->get(self::HEADER, '');
        $requestId = preg_match('/^[A-Za-z0-9\-_.]{8,64}$/', $incoming) === 1
            ? $incoming
            : 'req_'.Str::random(24);

        $request->attributes->set('request_id', $requestId);
        Log::shareContext([
            'request_id' => $requestId,
            'tenant_id' => $request->attributes->get('tenant')?->id,
        ]);

        $response = $next($request);
        $response->headers->set(self::HEADER, $requestId);

        return $response;
    }
}
