<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IdempotencyKey extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id', 'key', 'endpoint', 'request_hash',
        'response_status', 'response_body',
    ];

    protected function casts(): array
    {
        return ['response_body' => 'array', 'created_at' => 'datetime'];
    }
}
