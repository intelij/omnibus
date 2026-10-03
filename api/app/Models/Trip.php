<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property Carbon $departure_at
 * @property Carbon|null $arrival_estimate_at
 * @property Carbon|null $delayed_until
 */
class Trip extends Model
{
    use BelongsToTenant, HasFactory;

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_BOARDING = 'boarding';

    public const STATUS_DEPARTED = 'departed';

    public const STATUS_IN_TRANSIT = 'in_transit';

    public const STATUS_ARRIVED = 'arrived';

    public const STATUS_DELAYED = 'delayed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_COMPLETED = 'completed';

    public const BOOKABLE_STATUSES = [self::STATUS_SCHEDULED, self::STATUS_BOARDING, self::STATUS_DELAYED];

    protected $fillable = [
        'tenant_id', 'schedule_id', 'route_id', 'bus_id', 'driver_name',
        'departure_at', 'arrival_estimate_at', 'base_fare', 'status',
        'delayed_until', 'cancelled_at', 'cancellation_reason', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'departure_at' => 'datetime',
            'arrival_estimate_at' => 'datetime',
            'delayed_until' => 'datetime',
            'cancelled_at' => 'datetime',
            'base_fare' => 'decimal:2',
        ];
    }

    public function route(): BelongsTo
    {
        return $this->belongsTo(BusRoute::class, 'route_id');
    }

    public function bus(): BelongsTo
    {
        return $this->belongsTo(Bus::class);
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(Schedule::class);
    }

    public function seats(): HasMany
    {
        return $this->hasMany(TripSeat::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function isBookable(): bool
    {
        return in_array($this->status, self::BOOKABLE_STATUSES, true)
            && $this->departure_at->isFuture();
    }
}
