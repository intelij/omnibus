<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A finance-review flag raised when provider data doesn't match our records —
 * e.g. a paid amount that differs from the booking total, or a payment that
 * arrived after the booking expired. Open discrepancies block nothing; they
 * are resolved (with a refund where appropriate) by a finance officer.
 */
class PaymentDiscrepancy extends Model
{
    use BelongsToTenant;

    public const TYPE_AMOUNT_MISMATCH = 'amount_mismatch';

    public const TYPE_LATE_PAYMENT = 'late_payment';

    public const TYPE_STATUS_CONFLICT = 'status_conflict';

    protected $fillable = [
        'tenant_id', 'payment_id', 'type', 'expected_amount', 'received_amount',
        'status', 'notes', 'context', 'resolved_by', 'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'expected_amount' => 'decimal:2',
            'received_amount' => 'decimal:2',
            'context' => 'array',
            'resolved_at' => 'datetime',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
