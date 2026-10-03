<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use BelongsToTenant;

    use HasApiTokens, HasFactory, HasRoles, Notifiable;

    protected $guard_name = 'sanctum';

    protected $fillable = [
        'tenant_id', 'is_platform_user', 'name', 'email', 'phone',
        'password', 'status', 'last_login_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'is_platform_user' => 'boolean',
            'password' => 'hashed',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function tenants(): BelongsToMany
    {
        return $this->belongsToMany(Tenant::class, 'tenant_users')->withTimestamps();
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Platform roles live in spatie team "0" — outside any tenant.
     * is_platform_user + role check is done against the pivot directly so
     * it works regardless of the current tenant context.
     */
    public function isPlatformAdmin(): bool
    {
        if (! $this->is_platform_user) {
            return false;
        }

        return \DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_type', self::class)
            ->where('model_has_roles.model_id', $this->id)
            ->where('model_has_roles.tenant_id', 0)
            ->where('roles.name', 'platform_super_admin')
            ->exists();
    }

    public function can($abilities, $arguments = []): bool
    {
        if ($this->isPlatformAdmin()) {
            return true;
        }

        return parent::can($abilities, $arguments);
    }
}
