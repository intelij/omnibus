<?php

namespace Database\Seeders;

use App\Models\Bus;
use App\Models\BusRoute;
use App\Models\Promotion;
use App\Models\SeatLayout;
use App\Models\Tenant;
use App\Models\User;
use App\Services\BookingService;
use App\Services\PaymentService;
use App\Services\SeatLayoutGenerator;
use App\Services\TicketService;
use App\Services\TripService;
use App\Support\TenantContext;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Deterministic demo dataset — see docs/DEMO_DATA.md.
 *
 * Demo password for every seeded account: `password` (development only;
 * gated by APP_ENV — the seeder refuses production databases without
 * SEED_DEMO_IN_PRODUCTION=1 to keep demo creds out of live systems).
 */
class DatabaseSeeder extends Seeder
{
    private const DEMO_PASSWORD = 'password';

    public function run(): void
    {
        if (app()->isProduction() && ! config('app.demo_seeding')) {
            throw new \RuntimeException(
                'Demo seeding is disabled in production. Set SEED_DEMO_IN_PRODUCTION=1 to override deliberately.'
            );
        }

        $this->call(RolesAndPermissionsSeeder::class);
        $this->call(ZimbabweLocationsSeeder::class);

        // ── Platform administrator (cross-tenant, team "0") ─────────
        $platform = User::updateOrCreate(
            ['email' => 'admin@transithub.test'],
            [
                'name' => 'Platform Admin',
                'password' => self::DEMO_PASSWORD,
                'is_platform_user' => true,
                'status' => 'active',
                'email_verified_at' => now(),
            ],
        );
        app(PermissionRegistrar::class)->setPermissionsTeamId(0);
        $platform->syncRoles([Role::findByName('platform_super_admin', 'sanctum')]);

        $ze = $this->createTenant('zimbabwe-express', 'Zimbabwe Express (Pvt) Ltd', 'Zimbabwe Express', 'professional', '#7c3aed');
        $this->seedZimbabweExpress($ze);

        $bs = $this->createTenant('bulawayo-star', 'Bulawayo Star Coaches (Pvt) Ltd', 'Bulawayo Star Coaches', 'professional', '#b45309');
        $this->seedBulawayoStar($bs);
    }

    private function createTenant(string $slug, string $legal, string $trading, string $plan, string $primary): Tenant
    {
        return Tenant::updateOrCreate(
            ['slug' => $slug],
            [
                'legal_name' => $legal,
                'trading_name' => $trading,
                'registration_number' => 'REG-'.strtoupper(str_replace('-', '', $slug)),
                'email' => "ops@{$slug}.test",
                'phone' => '+263700000000',
                'currency' => 'USD',
                'timezone' => 'Africa/Harare',
                'locale' => 'en',
                'plan' => $plan,
                'status' => Tenant::STATUS_ACTIVE,
                'branding' => [
                    'primary_color' => $primary,
                    'secondary_color' => '#0f172a',
                    'accent_color' => '#f59e0b',
                    'header_text' => $trading,
                ],
                'settings' => [
                    'payment_methods' => ['cash', 'card', 'ecocash'],
                    'booking_rules' => config('transithub.default_booking_rules'),
                ],
            ],
        );
    }

    /** All tenant staff get the deterministic demo password + verified email. */
    private function seedStaff(Tenant $tenant): array
    {
        $staff = [
            'owner' => 'tenant_owner',
            'admin' => 'tenant_admin',
            'agent' => 'booking_agent',
            'finance' => 'finance_officer',
            'support' => 'support_agent',
        ];

        $users = [];
        foreach ($staff as $local => $role) {
            $user = User::updateOrCreate(
                ['email' => "{$local}@".str_replace('-', '', $tenant->slug).'.test'],
                [
                    'tenant_id' => $tenant->id,
                    'name' => ucfirst($local).' '.str($tenant->trading_name)->title(),
                    'password' => self::DEMO_PASSWORD,
                    'status' => 'active',
                    'email_verified_at' => now(),
                ],
            );
            $user->tenants()->syncWithoutDetaching([$tenant->id]);
            $user->syncRoles([Role::findByName($role, 'sanctum')]);
            $users[$local] = $user;
        }

        return $users;
    }

    /** Generated ZW layout via the real generator — never hand-built cells. */
    private function makeLayout(Tenant $tenant, string $name, int $rows, int $left, int $right, int $rear): SeatLayout
    {
        $layout = SeatLayout::firstOrCreate(
            ['name' => $name, 'tenant_id' => $tenant->id],
            ['decks' => 1, 'rows' => 0, 'cols' => 0, 'status' => 'draft'],
        );

        if ($layout->seats()->count() === 0) {
            $layout = app(SeatLayoutGenerator::class)->generate($layout, [
                'rows' => $rows, 'left_seats' => $left, 'right_seats' => $right,
                'rear_row_seats' => $rear,
            ]);
        }
        if ($layout->status !== 'published') {
            $layout->update(['status' => 'published']);
        }

        return $layout;
    }

    private function seedZimbabweExpress(Tenant $tenant): void
    {
        $ctx = app(TenantContext::class);
        $ctx->set($tenant);
        $users = $this->seedStaff($tenant);

        // Fleet: a 49-seater 2+2 with rear-5, and a 45-seater variant.
        $layout49 = $this->makeLayout($tenant, 'Standard 49-seater', 11, 2, 2, 5);
        $layout45 = $this->makeLayout($tenant, 'Standard 45-seater', 10, 2, 2, 5);

        $ze1 = Bus::updateOrCreate(
            ['registration_number' => 'ZE-001', 'tenant_id' => $tenant->id],
            [
                'seat_layout_id' => $layout49->id, 'fleet_number' => 'ZE-01',
                'make' => 'Scania', 'model' => 'Touring', 'year' => 2022,
                'bus_type' => 'coach', 'capacity' => 49,
                'amenities' => ['wifi', 'usb_charging', 'air_conditioning'],
                'status' => 'active',
            ],
        );
        $ze2 = Bus::updateOrCreate(
            ['registration_number' => 'ZE-002', 'tenant_id' => $tenant->id],
            [
                'seat_layout_id' => $layout45->id, 'fleet_number' => 'ZE-02',
                'make' => 'Volvo', 'model' => 'B8R', 'year' => 2023,
                'bus_type' => 'coach', 'capacity' => 45,
                'amenities' => ['air_conditioning'],
                'status' => 'active',
            ],
        );

        // Routes: the core ZE network, return directions included.
        $routes = [
            ['Harare', 'Bulawayo', 440, 330, 20],
            ['Bulawayo', 'Harare', 440, 330, 20],
            ['Harare', 'Mutare', 265, 210, 15],
            ['Mutare', 'Harare', 265, 210, 15],
            ['Harare', 'Gweru', 275, 200, 14],
            ['Gweru', 'Harare', 275, 200, 14],
            ['Harare', 'Masvingo', 295, 240, 18],
            ['Masvingo', 'Harare', 295, 240, 18],
        ];

        $trips = [];
        foreach ($routes as $i => [$from, $to, $km, $mins, $fare]) {
            $route = BusRoute::updateOrCreate(
                ['name' => "{$from} → {$to}", 'tenant_id' => $tenant->id],
                [
                    'origin' => $from, 'destination' => $to,
                    'distance_km' => $km, 'duration_minutes' => $mins,
                    'base_fare' => $fare, 'status' => 'active',
                ],
            );
            $this->seedStops($route, [['name' => $from, 'offset' => 0], ['name' => $to, 'offset' => $mins]]);

            // Dynamic departures: today+0…+7, alternating buses/times/fares.
            foreach ([0, 1, 2, 3, 7] as $day) {
                $trips[] = app(TripService::class)->create([
                    'route_id' => $route->id,
                    'bus_id' => ($day + $i) % 2 === 0 ? $ze1->id : $ze2->id,
                    'departure_at' => now()->addDays($day)->setTime($i % 2 ? 8 : 14, $i % 3 * 15),
                    'base_fare' => $fare + ($day === 0 ? 0 : ($day % 2)),
                ]);
            }
        }

        Promotion::updateOrCreate(
            ['code' => 'WELCOME10', 'tenant_id' => $tenant->id],
            [
                'name' => 'Welcome — 10% off',
                'type' => 'percent', 'value' => 10,
                'usage_limit' => 500, 'per_customer_limit' => 3,
                'ends_at' => now()->addMonths(3), 'status' => 'active',
            ],
        );

        $this->seedBookings($tenant, $users, $trips);

        $ctx->clear();
    }

    private function seedBulawayoStar(Tenant $tenant): void
    {
        $ctx = app(TenantContext::class);
        $ctx->set($tenant);
        $this->seedStaff($tenant);

        $layout49 = $this->makeLayout($tenant, 'Standard 49-seater', 11, 2, 2, 5);
        $layoutLux = $this->makeLayout($tenant, 'Executive 30-seater', 10, 2, 1, 0);

        $bs1 = Bus::updateOrCreate(
            ['registration_number' => 'BS-001', 'tenant_id' => $tenant->id],
            [
                'seat_layout_id' => $layout49->id, 'fleet_number' => 'BS-01',
                'make' => 'Mercedes-Benz', 'model' => 'OH1726', 'year' => 2021,
                'bus_type' => 'coach', 'capacity' => 49,
                'amenities' => ['air_conditioning'], 'status' => 'active',
            ],
        );
        $bs2 = Bus::updateOrCreate(
            ['registration_number' => 'BS-002', 'tenant_id' => $tenant->id],
            [
                'seat_layout_id' => $layoutLux->id, 'fleet_number' => 'BS-02',
                'make' => 'MAN', 'model' => 'Lions Coach', 'year' => 2024,
                'bus_type' => 'vip_coach', 'capacity' => 30,
                'amenities' => ['wifi', 'usb_charging', 'air_conditioning', 'refreshments'],
                'status' => 'active',
            ],
        );

        $routes = [
            ['Harare', 'Chinhoyi', 115, 90, 8],
            ['Chinhoyi', 'Harare', 115, 90, 8],
            ['Harare', 'Victoria Falls', 880, 630, 45],
            ['Victoria Falls', 'Harare', 880, 630, 45],
            ['Bulawayo', 'Victoria Falls', 440, 360, 30],
            ['Victoria Falls', 'Bulawayo', 440, 360, 30],
        ];

        foreach ($routes as $i => [$from, $to, $km, $mins, $fare]) {
            $route = BusRoute::updateOrCreate(
                ['name' => "{$from} → {$to}", 'tenant_id' => $tenant->id],
                [
                    'origin' => $from, 'destination' => $to,
                    'distance_km' => $km, 'duration_minutes' => $mins,
                    'base_fare' => $fare, 'status' => 'active',
                ],
            );
            $this->seedStops($route, [['name' => $from, 'offset' => 0], ['name' => $to, 'offset' => $mins]]);

            foreach ([0, 1, 3] as $day) {
                app(TripService::class)->create([
                    'route_id' => $route->id,
                    'bus_id' => ($day + $i) % 2 === 0 ? $bs1->id : $bs2->id,
                    'departure_at' => now()->addDays($day)->setTime($i % 2 ? 6 : 15, 30),
                    'base_fare' => $fare,
                ]);
            }
        }

        Promotion::updateOrCreate(
            ['code' => 'BULAWAYO5', 'tenant_id' => $tenant->id],
            [
                'name' => 'Bulawayo Star — $5 off',
                'type' => 'fixed', 'value' => 5,
                'usage_limit' => 200, 'per_customer_limit' => 1,
                'ends_at' => now()->addMonths(3), 'status' => 'active',
            ],
        );

        $ctx->clear();
    }

    /** Bookings in several states — 1-seat confirmed, 3-seat confirmed, 4-seat pending, a cancelled one. */
    private function seedBookings(Tenant $tenant, array $users, array $trips): void
    {
        $bookings = app(BookingService::class);
        $payments = app(PaymentService::class);
        $tickets = app(TicketService::class);

        // 1-seat confirmed (cash paid → confirmed → ticketed)
        $t1 = $trips[1] ?? null;
        if ($t1) {
            $stops = $t1->route->stops()->orderBy('sequence')->get();
            $b = $bookings->create([
                'trip_id' => $t1->id,
                'boarding_stop_id' => $stops->first()->id,
                'dropoff_stop_id' => $stops->last()->id,
                'boarding_sequence' => $stops->first()->sequence,
                'dropoff_sequence' => $stops->last()->sequence,
                'contact_name' => 'Tendai Ncube',
                'contact_email' => 'tendai@example.com',
                'contact_phone' => '+263771234567',
                'passengers' => [['first_name' => 'Tendai', 'last_name' => 'Ncube']],
            ]);
            $payments->confirmCash($payments->initiate($b->fresh(), 'cash'), $users['finance']);
            $tickets->issueFor($b->fresh());
        }

        // 3-seat confirmed
        $t2 = $trips[2] ?? null;
        if ($t2) {
            $stops = $t2->route->stops()->orderBy('sequence')->get();
            $b = $bookings->create([
                'trip_id' => $t2->id,
                'boarding_stop_id' => $stops->first()->id,
                'dropoff_stop_id' => $stops->last()->id,
                'boarding_sequence' => $stops->first()->sequence,
                'dropoff_sequence' => $stops->last()->sequence,
                'contact_name' => 'Jane Moyo',
                'contact_email' => 'jane@example.com',
                'contact_phone' => '+263777000111',
                'passengers' => [
                    ['first_name' => 'Jane', 'last_name' => 'Moyo'],
                    ['first_name' => 'Blessing', 'last_name' => 'Dube'],
                    ['first_name' => 'Tatenda', 'last_name' => 'Sibanda'],
                ],
            ]);
            $payments->confirmCash($payments->initiate($b->fresh(), 'cash'), $users['finance']);
            $tickets->issueFor($b->fresh());
        }

        // 4-seat pending (no payment yet — stay open for testing)
        $t3 = $trips[3] ?? null;
        if ($t3) {
            $stops = $t3->route->stops()->orderBy('sequence')->get();
            $b = $bookings->create([
                'trip_id' => $t3->id,
                'boarding_stop_id' => $stops->first()->id,
                'dropoff_stop_id' => $stops->last()->id,
                'boarding_sequence' => $stops->first()->sequence,
                'dropoff_sequence' => $stops->last()->sequence,
                'contact_name' => 'Rudo Chikafu',
                'contact_phone' => '+263773334455',
                'payment_method' => null,
                'expires_at' => now()->addDays(2),
                'passengers' => [
                    ['first_name' => 'Rudo', 'last_name' => 'Chikafu'],
                    ['first_name' => 'Panashe', 'last_name' => 'Gumbo'],
                    ['first_name' => 'Nyasha', 'last_name' => 'Zvobgo'],
                    ['first_name' => 'Kuda', 'last_name' => 'Marowa'],
                ],
            ]);
        }

        // One cancelled booking for realism
        $t4 = $trips[4] ?? null;
        if ($t4) {
            $stops = $t4->route->stops()->orderBy('sequence')->get();
            $b = $bookings->create([
                'trip_id' => $t4->id,
                'boarding_stop_id' => $stops->first()->id,
                'dropoff_stop_id' => $stops->last()->id,
                'boarding_sequence' => $stops->first()->sequence,
                'dropoff_sequence' => $stops->last()->sequence,
                'contact_name' => 'Farai Mutasa',
                'contact_phone' => '+263715550999',
                'passengers' => [['first_name' => 'Farai', 'last_name' => 'Mutasa']],
            ]);
            $bookings->cancel($b->fresh(), $users['owner'] ?? null, 'Customer requested cancellation');
        }
    }

    private function seedStops(BusRoute $route, array $stops): void
    {
        if ($route->stops()->count()) {
            return; // idempotent
        }
        foreach ($stops as $i => $stop) {
            $route->stops()->create([
                'tenant_id' => $route->tenant_id,
                'name' => $stop['name'],
                'sequence' => $i,
                'can_board' => $i < count($stops) - 1,
                'can_alight' => $i > 0,
                'offset_minutes' => $stop['offset'],
            ]);
        }
    }
}
