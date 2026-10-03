<?php

namespace Tests\Feature;

use App\Models\Bus;
use App\Models\BusRoute;
use App\Models\Location;
use App\Models\Promotion;
use App\Models\Tenant;
use App\Models\Trip;
use App\Models\TripSeat;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** Seeded demo dataset invariants — migrate:fresh --seed must yield a usable app. */
class DemoDataTest extends TestCase
{
    public function test_demo_seed_produces_a_usable_dataset(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertGreaterThanOrEqual(2, Tenant::count());
        $this->assertGreaterThanOrEqual(30, Location::active()->count());
        $this->assertGreaterThanOrEqual(10, BusRoute::count());
        $this->assertGreaterThanOrEqual(4, Bus::count());
        $this->assertGreaterThanOrEqual(2, Promotion::where('status', 'active')->count());
        $this->assertGreaterThan(0, Trip::where('departure_at', '>', now())->count());
        $this->assertGreaterThan(0, TripSeat::count());

        $admin = User::where('email', 'admin@transithub.test')->first();
        $this->assertTrue($admin->is_platform_user && Hash::check('password', $admin->password));

        foreach (['admin@zimbabweexpress.test', 'admin@bulawayostar.test'] as $email) {
            $this->assertTrue(
                User::where('email', $email)->where('status', 'active')
                    ->whereNotNull('email_verified_at')->exists(),
                "{$email} missing or inactive"
            );
        }
    }

    public function test_demo_trip_search_returns_seeded_results(): void
    {
        $this->seed(DatabaseSeeder::class);
        $tenant = Tenant::where('slug', 'zimbabwe-express')->first();

        $res = $this->publicGet($tenant, '/api/v1/public/trips/search?from=Harare&to=Bulawayo&date='.now()->addDay()->toDateString());
        $res->assertOk();
        $this->assertNotEmpty($res->json('data'), 'Expected seeded Harare→Bulawayo trips');
    }

    public function test_demo_promo_is_tenant_scoped(): void
    {
        $this->seed(DatabaseSeeder::class);
        $ze = Tenant::where('slug', 'zimbabwe-express')->first();
        $bs = Tenant::where('slug', 'bulawayo-star')->first();

        // WELCOME10 belongs to ZE; BS trip search must not accept it.
        $bsTrip = Trip::where('tenant_id', $bs->id)->where('departure_at', '>', now())->orderBy('departure_at')->first();
        $payload = $this->bookingPayload($bsTrip);
        $payload['promotion_code'] = 'WELCOME10';

        $res = $this->publicPost($bs, '/api/v1/public/bookings', $payload);
        // Promo must be rejected (422) or ignored — never applied cross-tenant.
        $this->assertNotEquals(201, $res->status(), 'Cross-tenant promo code applied');
    }
}
