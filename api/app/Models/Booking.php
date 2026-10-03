<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Booking extends Model
{
    use BelongsToTenant, HasFactory;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_HELD = 'held';
    public const STATUS_PENDING_PAYMENT = 'pending_payment';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_REFUNDED = 'refunded';

    public const ACTIVE_STATUSES = [self::STATUS_HELD, self::STATUS_PENDING_PAYMENT, self::STATUS_CONFIRMED];

    protected $fillable = [
        'tenant_id', 'trip_id', 'user_id', 'booking_reference', 'status',
        'subtotal', 'discount_amount', 'total', 'currency',
        'promotion_code', 'promotion_id', 'payment_method', 'ticket_type',
        'contact_name', 'contact_email', 'contact_phone',
        'boarding_stop_id', 'dropoff_stop_id',
        'boarding_sequence', 'dropoff_sequence',
        'idempotency_key', 'pricing_snapshot', 'expires_at',
        'confirmed_at', 'cancelled_at', 'cancellation_reason',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'pricing_snapshot' => 'array',
            'expires_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function passengers(): HasMany
    {
        return $this->hasMany(BookingPassenger::class);
    }

    public function seats(): HasMany
    {
        return $this->hasMany(BookingSeat::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(BookingEvent::class)->orderBy('created_at');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function latestPayment(): HasOne
    {
        return $this->hasOne(Payment::class)->latestOfMany();
    }

    public function openPayment(): HasOne
    {
        return $this->hasOne(Payment::class)
            ->whereIn('status', [Payment::STATUS_PENDING, Payment::STATUS_PROCESSING])
            ->latestOfMany();
    }

    public function paidPayment(): HasOne
    {
        return $this->hasOne(Payment::class)->where('status', Payment::STATUS_PAID)->latestOfMany();
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class);
    }

    public function boardingStop(): BelongsTo
    {
        return $this->belongsTo(RouteStop::class, 'boarding_stop_id');
    }

    public function dropoffStop(): BelongsTo
    {
        return $this->belongsTo(RouteStop::class, 'dropoff_stop_id');
    }

    public function isCancellable(): bool
    {
        return in_array($this->status, [self::STATUS_HELD, self::STATUS_PENDING_PAYMENT, self::STATUS_CONFIRMED], true)
            && $this->trip?->departure_at?->isFuture();
    }

    public function logEvent(string $type, ?int $actorId = null, ?string $actorType = null, array $metadata = []): BookingEvent
    {
        return $this->events()->create([
            'tenant_id' => $this->tenant_id,
            'type' => $type,
            'actor_id' => $actorId,
            'actor_type' => $actorType,
            'metadata' => $metadata,
        ]);
    }
}
