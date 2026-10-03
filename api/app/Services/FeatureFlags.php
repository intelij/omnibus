<?php

namespace App\Services;

use App\Models\Tenant;

/**
 * Tenant-aware feature flags. Tenant overrides > plan defaults.
 * Flags gate features, never replace authorization checks.
 */
class FeatureFlags
{
    public static function enabled(Tenant $tenant, string $feature): bool
    {
        $override = $tenant->features()->where('feature', $feature)->first();
        if ($override) {
            return $override->enabled;
        }

        $plan = config("transithub.plans.{$tenant->plan}", config('transithub.plans.starter'));

        return (bool) ($plan[$feature] ?? false);
    }

    /** @return array<string,bool> */
    public static function all(Tenant $tenant): array
    {
        $plan = config("transithub.plans.{$tenant->plan}", config('transithub.plans.starter'));
        foreach ($tenant->features as $f) {
            $plan[$f->feature] = $f->enabled;
        }

        return $plan;
    }
}
