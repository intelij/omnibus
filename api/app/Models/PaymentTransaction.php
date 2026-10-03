<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentTransaction extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    public const TYPE_INITIATE = 'initiate';
    public const TYPE_CALLBACK = 'callback';
    public const TYPE_STATUS_CHECK = 'status_check';
    public const TYPE_REFUND = 'refund';
    public const TYPE_CONFIRMATION = 'confirmation';

    protected $fillable = [
        'tenant_id', 'payment_id', 'type', 'provider_event_id', 'status',
        'amount', 'payload', 'payload_hash', 'result',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'payload' => 'array',
            'result' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
