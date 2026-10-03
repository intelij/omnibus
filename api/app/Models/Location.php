<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Canonical place dataset (Zimbabwe today; country_code leaves room to grow).
 * Deliberately not tenant-scoped — a location is geography, not tenant data.
 * Operators map route stops onto these via route_stops.location_id.
 */
class Location extends Model
{
    public const TYPE_CITY = 'city';

    public const TYPE_TOWN = 'town';

    protected $fillable = [
        'name', 'slug', 'type', 'province', 'country_code',
        'latitude', 'longitude', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'latitude' => 'decimal:6',
            'longitude' => 'decimal:6',
        ];
    }

    public function scopeActive($q)
    {
        return $q->where('is_active', true);
    }

    /** Case-insensitive partial-name search, portable across sqlite/pgsql. */
    public function scopeSearch($q, string $term)
    {
        return $q->whereRaw('lower(name) like ?', ['%'.mb_strtolower($term).'%']);
    }

    public function stops(): HasMany
    {
        return $this->hasMany(RouteStop::class);
    }
}
