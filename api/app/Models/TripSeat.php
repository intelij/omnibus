<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TripSeat extends Model
{
    use BelongsToTenant, HasFactory;

    public const STATE_AVAILABLE = 'available';
    public const STATE_BLOCKED = 'blocked';
    public const STATE_MAINTENANCE = 'maintenance';

    protected $fillable = [
        'tenant_id', 'trip_id', 'seat_id', 'deck', 'row', 'col', 'label',
        'type', 'position', 'price_modifier', 'state',
    ];

    protected function casts(): array
    {
        return [
            'price_modifier' => 'decimal:2',
        ];
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    public function layoutSeat(): BelongsTo
    {
        return $this->belongsTo(SeatLayoutSeat::class, 'seat_id');
    }

    public function legs(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(TripSeatLeg::class)->orderBy('leg_index');
    }

    public function isBookableType(): bool
    {
        return ! in_array($this->type, SeatLayoutSeat::NON_BOOKABLE_TYPES, true);
    }

    public function isOperatorAvailable(): bool
    {
        return $this->state === self::STATE_AVAILABLE;
    }
}
