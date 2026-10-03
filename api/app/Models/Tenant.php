<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Tenant extends Model
{
    use HasFactory, SoftDeletes;

    public const STATUS_PENDING = 'pending';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_ARCHIVED = 'archived';

    protected $fillable = [
        'slug', 'legal_name', 'trading_name', 'registration_number',
        'email', 'phone', 'address', 'website', 'social_links',
        'currency', 'timezone', 'locale', 'plan', 'status',
        'branding', 'custom_domain', 'settings', 'onboarding',
    ];

    protected function casts(): array
    {
        return [
            'address' => 'array',
            'social_links' => 'array',
            'branding' => 'array',
            'settings' => 'array',
            'onboarding' => 'array',
        ];
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'tenant_users')
            ->withPivot(['invited_by', 'created_at'])
            ->withTimestamps();
    }

    public function features(): HasMany
    {
        return $this->hasMany(TenantFeature::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function brandingValue(string $key, mixed $default = null): mixed
    {
        return data_get($this->branding, $key, $default);
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return data_get($this->settings, $key, $default);
    }

    public function bookingRule(string $key, mixed $default = null): mixed
    {
        $rules = config('transithub.default_booking_rules', []);

        return $this->setting("booking_rules.{$key}", $rules[$key] ?? $default);
    }
}
