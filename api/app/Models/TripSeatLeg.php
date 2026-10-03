<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per (trip, seat, route-leg) inventory row. A seat booked for legs
 * [boarding..dropoff) remains available on disjoint legs — this is what
 * makes Harare→Kwekwe free the seat for Kwekwe→Bulawayo.
 */
class TripSeatLeg extends Model
{
    use BelongsToTenant, HasFactory;

    public const STATE_AVAILABLE = 'available';
    public const STATE_HELD = 'held';
    public const STATE_BOOKED = 'booked';

    protected $fillable = [
        'tenant_id', 'trip_id', 'trip_seat_id', 'leg_index', 'state',
        'held_until', 'hold_token', 'booking_id',
    ];

    protected function casts(): array
    {
        return ['held_until' => 'datetime'];
    }

    public function tripSeat(): BelongsTo
    {
        return $this->belongsTo(TripSeat::class);
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function effectivelyAvailable(): bool
    {
        if ($this->state === self::STATE_AVAILABLE) {
            return true;
        }

        return $this->state === self::STATE_HELD
            && $this->held_until !== null
            && $this->held_until->isPast();
    }
}
