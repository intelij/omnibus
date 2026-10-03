<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\TenantFeature;
use App\Models\User;
use App\Support\Audit;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

class TenantController extends Controller
{
    public function index(Request $request)
    {
        return response()->json(
            Tenant::when($request->status, fn ($q, $s) => $q->where('status', $s))
                ->when($request->q, fn ($q, $v) => $q->where('trading_name', 'like', "%{$v}%"))
                ->orderBy('created_at', 'desc')
                ->paginate($request->integer('per_page', 20))
        );
    }

    /** Create a tenant + its owner user in one shot. */
    public function store(Request $request)
    {
        $data = $request->validate([
            'legal_name' => ['required', 'string', 'max:160'],
            'trading_name' => ['required', 'string', 'max:160'],
            'slug' => ['required', 'string', 'max:60', 'alpha_dash', Rule::unique('tenants')],
            'email' => ['required', 'email'],
            'phone' => ['nullable', 'string', 'max:30'],
            'currency' => ['required', 'string', 'size:3'],
            'timezone' => ['required', 'timezone'],
            'plan' => ['required', Rule::in(array_keys(config('transithub.plans')))],
            'owner.name' => ['required', 'string', 'max:120'],
            'owner.email' => ['required', 'email', Rule::unique('users', 'email')],
            'owner.password' => ['required', 'string', 'min:10'],
        ]);

        [$tenant, $owner] = DB::transaction(function () use ($data) {
            $tenant = Tenant::create([
                'slug' => Str::slug($data['slug']),
                'legal_name' => $data['legal_name'],
                'trading_name' => $data['trading_name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'currency' => strtoupper($data['currency']),
                'timezone' => $data['timezone'],
                'plan' => $data['plan'],
                'status' => Tenant::STATUS_PENDING,
                'settings' => ['payment_methods' => ['cash']],
            ]);

            $owner = User::create([
                'tenant_id' => $tenant->id,
                'name' => $data['owner']['name'],
                'email' => $data['owner']['email'],
                'password' => $data['owner']['password'],
            ]);
            $owner->tenants()->attach($tenant->id);

            app(TenantContext::class)->set($tenant);
            $owner->assignRole(Role::findByName('tenant_owner', 'sanctum'));

            return [$tenant, $owner];
        });

        Audit::record('tenant.created', $tenant, ['owner' => $owner->email]);

        return response()->json($tenant, 201);
    }

    public function show(Tenant $tenant)
    {
        return response()->json($tenant->load(['users' => fn ($q) => $q->select('users.id', 'name', 'email', 'status')]));
    }

    public function setStatus(Tenant $tenant, Request $request)
    {
        $data = $request->validate([
            'status' => ['required', Rule::in([Tenant::STATUS_ACTIVE, Tenant::STATUS_SUSPENDED, Tenant::STATUS_ARCHIVED, Tenant::STATUS_PENDING])],
        ]);

        $tenant->update($data);
        Audit::record("tenant.{$data['status']}", $tenant, tenantId: $tenant->id);

        // Suspending a tenant revokes its users' tokens immediately.
        if ($data['status'] === Tenant::STATUS_SUSPENDED) {
            $tenant->users()->each(fn ($u) => $u->tokens()->delete());
        }

        return response()->json($tenant);
    }

    public function setFeatures(Tenant $tenant, Request $request)
    {
        $data = $request->validate([
            'features' => ['required', 'array'],
            'features.*.feature' => ['required', 'string', 'max:60'],
            'features.*.enabled' => ['required', 'boolean'],
        ]);

        foreach ($data['features'] as $f) {
            TenantFeature::updateOrCreate(
                ['tenant_id' => $tenant->id, 'feature' => $f['feature']],
                ['enabled' => $f['enabled']]
            );
        }

        Audit::record('tenant.features_updated', $tenant, $data, tenantId: $tenant->id);

        return response()->json($tenant->features);
    }
}
