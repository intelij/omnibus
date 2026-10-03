<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class BusRoute extends Model
{
    use BelongsToTenant, HasFactory, SoftDeletes;

    protected $table = 'routes';

    protected $fillable = [
        'tenant_id', 'name', 'origin', 'destination', 'distance_km',
        'duration_minutes', 'base_fare', 'status',
    ];

    protected function casts(): array
    {
        return ['base_fare' => 'decimal:2'];
    }

    public function stops(): HasMany
    {
        return $this->hasMany(RouteStop::class, 'route_id')->orderBy('sequence');
    }

    public function fares(): HasMany
    {
        return $this->hasMany(RouteFare::class, 'route_id');
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(Schedule::class, 'route_id');
    }

    public function trips(): HasMany
    {
        return $this->hasMany(Trip::class, 'route_id');
    }

    public function fareFor(int $fromSequence, int $toSequence, string $passengerType = 'adult'): ?RouteFare
    {
        return $this->fares()
            ->where(function ($q) use ($fromSequence, $toSequence) {
                $q->where(function ($q2) use ($fromSequence, $toSequence) {
                    $q2->where('from_sequence', $fromSequence)->where('to_sequence', $toSequence);
                })->orWhere(function ($q2) {
                    $q2->whereNull('from_sequence')->whereNull('to_sequence');
                });
            })
            ->where('passenger_type', $passengerType)
            ->orderByRaw('case when from_sequence is null then 1 else 0 end')
            ->first();
    }
}
