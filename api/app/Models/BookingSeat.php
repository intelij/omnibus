<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookingSeat extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'booking_id', 'trip_seat_id', 'booking_passenger_id',
        'seat_label', 'fare', 'boarding_sequence', 'dropoff_sequence', 'status',
    ];

    protected function casts(): array
    {
        return ['fare' => 'decimal:2'];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function tripSeat(): BelongsTo
    {
        return $this->belongsTo(TripSeat::class);
    }

    public function passenger(): BelongsTo
    {
        return $this->belongsTo(BookingPassenger::class, 'booking_passenger_id');
    }

    public function occupies(int $fromSequence, int $toSequence): bool
    {
        return $this->status === 'active'
            && $this->boarding_sequence < $toSequence
            && $fromSequence < $this->dropoff_sequence;
    }
}
