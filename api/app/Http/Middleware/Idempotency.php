<?php

namespace App\Http\Middleware;

use App\Models\IdempotencyKey;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Idempotency-Key middleware for mutating endpoints. Replays the stored
 * response for repeat keys; 409s on key reuse with a different payload.
 */
class Idempotency
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('Idempotency-Key');

        if (! $key || strlen($key) > 128) {
            return $next($request);
        }

        $tenantId = $request->attributes->get('tenant')?->id ?? tenant_id();
        $endpoint = $request->method() . ' ' . $request->path();
        $requestHash = hash('sha256', $request->getContent());

        $existing = IdempotencyKey::where('tenant_id', $tenantId)
            ->where('key', $key)->where('endpoint', $endpoint)->first();

        if ($existing) {
            if ($existing->request_hash !== $requestHash) {
                return response()->json(['message' => 'Idempotency key reused with a different request.'], 409);
            }

            return response()->json(
                $existing->response_body ?? ['message' => 'Request already processed.'],
                $existing->response_status ?? 200,
            );
        }

        $record = IdempotencyKey::create([
            'tenant_id' => $tenantId,
            'key' => $key,
            'endpoint' => $endpoint,
            'request_hash' => $requestHash,
        ]);

        $response = $next($request);

        if ($response->getStatusCode() < 500) {
            $body = json_decode($response->getContent(), true);
            $record->update([
                'response_status' => $response->getStatusCode(),
                'response_body' => is_array($body) ? $body : ['raw' => true],
            ]);
        }

        return $response;
    }
}
