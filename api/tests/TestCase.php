<?php

namespace Tests;

use App\Models\Bus;
use App\Models\BusRoute;
use App\Models\SeatLayout;
use App\Models\Tenant;
use App\Models\Trip;
use App\Models\User;
use App\Services\TripService;
use App\Support\TenantContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();
        parent::tearDown();
    }

    protected function makeTenant(array $overrides = []): Tenant
    {
        return Tenant::create(array_merge([
            'slug' => 'tenant-'.uniqid(),
            'legal_name' => 'Test Transport Ltd',
            'trading_name' => 'Test Transport',
            'email' => 'ops@example.com',
            'currency' => 'USD',
            'timezone' => 'Africa/Harare',
            'plan' => 'professional',
            'status' => Tenant::STATUS_ACTIVE,
            'settings' => ['payment_methods' => ['cash', 'card', 'ecocash']],
        ], $overrides));
    }

    protected function makeUser(Tenant $tenant, string $role, array $overrides = []): User
    {
        app(TenantContext::class)->set($tenant);

        $user = User::create(array_merge([
            'tenant_id' => $tenant->id,
            'name' => ucfirst(str_replace('_', ' ', $role)),
            'email' => $role.'.'.uniqid().'@example.com',
            'password' => 'password',
            'status' => 'active',
        ], $overrides));
        $user->tenants()->attach($tenant->id);
        $user->assignRole(Role::findByName($role, 'sanctum'));

        return $user;
    }

    protected function makePlatformAdmin(): User
    {
        $admin = User::create([
            'name' => 'Platform Admin', 'email' => 'admin'.uniqid().'@platform.test',
            'password' => 'password', 'is_platform_user' => true, 'status' => 'active',
        ]);
        app(PermissionRegistrar::class)->setPermissionsTeamId(0);
        $admin->assignRole(Role::findByName('platform_super_admin', 'sanctum'));

        return $admin;
    }

    /** Build a fully-bookable trip: 4x3 layout, route with 3 stops, seats materialised. */
    protected function makeTrip(Tenant $tenant, array $opts = []): Trip
    {
        $ctx = app(TenantContext::class);
        $ctx->set($tenant);

        $layout = SeatLayout::create(['name' => 'test-coach', 'decks' => 1, 'rows' => 3, 'cols' => 4, 'status' => 'published']);
        $n = 1;
        for ($r = 1; $r <= 3; $r++) {
            for ($c = 1; $c <= 4; $c++) {
                $layout->seats()->create([
                    'tenant_id' => $tenant->id, 'deck' => 1, 'row' => $r, 'col' => $c,
                    'label' => (string) $n, 'type' => 'standard',
                    'position' => in_array($c, [1, 4]) ? 'window' : 'aisle',
                    'is_bookable' => true, 'sort_order' => $n,
                ]);
                $n++;
            }
        }

        $bus = Bus::create([
            'seat_layout_id' => $layout->id, 'registration_number' => 'BUS-'.uniqid(),
            'bus_type' => 'coach', 'capacity' => 12, 'status' => 'active',
        ]);

        $route = BusRoute::create([
            'name' => 'Harare → Bulawayo', 'origin' => 'Harare', 'destination' => 'Bulawayo',
            'duration_minutes' => 300, 'base_fare' => 25, 'status' => 'active',
        ]);
        $route->stops()->createMany([
            ['tenant_id' => $tenant->id, 'name' => 'Harare', 'sequence' => 0, 'can_board' => true, 'can_alight' => false, 'offset_minutes' => 0],
            ['tenant_id' => $tenant->id, 'name' => 'Kwekwe', 'sequence' => 1, 'can_board' => true, 'can_alight' => true, 'offset_minutes' => 150],
            ['tenant_id' => $tenant->id, 'name' => 'Bulawayo', 'sequence' => 2, 'can_board' => false, 'can_alight' => true, 'offset_minutes' => 300],
        ]);

        $trip = app(TripService::class)->create([
            'route_id' => $route->id,
            'bus_id' => $bus->id,
            'departure_at' => now()->addDay()->setTime(8, 0),
            'base_fare' => $opts['base_fare'] ?? 25,
        ]);

        return $trip->fresh();
    }

    protected function publicPost(Tenant $tenant, string $uri, array $data = [], array $headers = [])
    {
        return $this->postJson($uri, $data, array_merge(['X-Tenant' => $tenant->slug], $headers));
    }

    protected function publicGet(Tenant $tenant, string $uri)
    {
        return $this->getJson($uri.(str_contains($uri, '?') ? '&' : '?').'tenant='.$tenant->slug);
    }

    protected function bookingPayload(Trip $trip, array $overrides = []): array
    {
        // Route lookup must bypass the current tenant context — tests can
        // call this while another tenant's context is bound.
        $route = BusRoute::withoutTenantScope()->findOrFail($trip->route_id);

        return array_merge([
            'trip_id' => $trip->id,
            'boarding_stop_id' => $route->stops()->withoutTenantScope()->where('sequence', 0)->value('id'),
            'dropoff_stop_id' => $route->stops()->withoutTenantScope()->where('sequence', 2)->value('id'),
            'boarding_sequence' => 0,
            'dropoff_sequence' => 2,
            'contact_name' => 'Test Pax',
            'contact_email' => 'pax@example.com',
            'contact_phone' => '+263770000000',
            'payment_method' => 'cash',
            'passengers' => [['first_name' => 'Test', 'last_name' => 'Pax']],
        ], $overrides);
    }
}
