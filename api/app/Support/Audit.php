<?php

namespace App\Support;

use App\Models\AuditLog;

/**
 * Append-only audit writer. There is intentionally no update/delete path;
 * in production the DB user should have INSERT/SELECT only on audit_logs.
 */
class Audit
{
    public static function record(
        string $action,
        ?object $subject = null,
        ?array $metadata = null,
        ?int $userId = null,
        ?int $tenantId = null,
    ): AuditLog {
        $request = app()->bound('request') ? request() : null;

        return AuditLog::create([
            'tenant_id' => $tenantId ?? tenant_id(),
            'user_id' => $userId ?? $request?->user()?->id,
            'action' => $action,
            'auditable_type' => $subject ? get_class($subject) : null,
            'auditable_id' => $subject?->id ?? null,
            'ip' => $request?->ip(),
            'request_id' => $request?->attributes->get('request_id'),
            'user_agent' => substr((string) $request?->userAgent(), 0, 255),
            'metadata' => Mask::metadata($metadata),
        ]);
    }
}
