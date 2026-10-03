<?php

namespace Database\Seeders;

use App\Models\Bus;
use App\Models\BusRoute;
use App\Models\Promotion;
use App\Models\Schedule;
use App\Models\SeatLayout;
use App\Models\SeatLayoutSeat;
use App\Models\Tenant;
use App\Models\Trip;
use App\Models\User;
use App\Services\BookingService;
use App\Services\PaymentService;
use App\Services\TicketService;
use App\Services\TripService;
use App\Support\TenantContext;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(RolesAndPermissionsSeeder::class);
        $this->call(ZimbabweLocationsSeeder::class);

        // ── Platform administrator (no tenant) ─────────────────────
        $platform = User::create([
            'name' => 'Platform Admin',
            'email' => 'admin@transithub.test',
            'password' => 'Platform#2026',
            'is_platform_user' => true,
            'status' => 'active',
        ]);
        // Platform roles are assigned in team "0" — outside any tenant.
        app(PermissionRegistrar::class)->setPermissionsTeamId(0);
        $platform->assignRole(Role::findByName('platform_super_admin', 'sanctum'));

        // ── Tenant A (full demo operator) ──────────────────────────
        $tenantA = $this->createTenant('demo-transport', 'Demo Transport (Pvt) Ltd', 'Demo Transport', 'professional');
        $this->seedOperator($tenantA, full: true);

        // ── Tenant B (minimal — exists to prove isolation) ──────────
        $tenantB = $this->createTenant('zambezi-coaches', 'Zambezi Coaches Ltd', 'Zambezi Coaches', 'starter');
        $this->seedOperator($tenantB, full: false);
    }

    private function createTenant(string $slug, string $legal, string $trading, string $plan): Tenant
    {
        return Tenant::create([
            'slug' => $slug,
            'legal_name' => $legal,
            'trading_name' => $trading,
            'registration_number' => 'REG-'.strtoupper($slug),
            'email' => "ops@{$slug}.example.com",
            'phone' => '+263700000000',
            'currency' => 'USD',
            'timezone' => 'Africa/Harare',
            'locale' => 'en',
            'plan' => $plan,
            'status' => Tenant::STATUS_ACTIVE,
            'branding' => [
                'primary_color' => '#0f766e',
                'secondary_color' => '#134e4a',
                'accent_color' => '#f59e0b',
                'header_text' => $trading,
            ],
            'settings' => [
                'payment_methods' => ['cash', 'card', 'ecocash'],
                'booking_rules' => config('transithub.default_booking_rules'),
            ],
        ]);
    }

    private function seedOperator(Tenant $tenant, bool $full): void
    {
        $ctx = app(TenantContext::class);
        $ctx->set($tenant);

        // Users covering every tenant role
        $staff = [
            ['owner@'.$tenant->slug.'.example.com', 'Tenant Owner', 'tenant_owner'],
            ['admin@'.$tenant->slug.'.example.com', 'Tenant Admin', 'tenant_admin'],
            ['agent@'.$tenant->slug.'.example.com', 'Booking Agent', 'booking_agent'],
            ['finance@'.$tenant->slug.'.example.com', 'Finance Officer', 'finance_officer'],
            ['support@'.$tenant->slug.'.example.com', 'Support Agent', 'support_agent'],
        ];

        foreach ($staff as [$email, $name, $role]) {
            $user = User::create([
                'tenant_id' => $tenant->id,
                'name' => $name,
                'email' => $email,
                'password' => 'Password#2026',
                'status' => 'active',
            ]);
            $user->tenants()->attach($tenant->id);
            $user->assignRole(Role::findByName($role, 'sanctum'));
        }

        if (! $full) {
            // Minimal viable config for Tenant B — one bus/layout/route.
            $layout = $this->createCoachLayout($tenant, 'Standard 22-seater', 6, 4);
            $bus = Bus::create([
                'seat_layout_id' => $layout->id,
                'registration_number' => 'ZCB-001',
                'bus_type' => 'coach',
                'capacity' => 22,
                'status' => 'active',
            ]);

            $route = BusRoute::create([
                'name' => 'Bulawayo → Victoria Falls',
                'origin' => 'Bulawayo',
                'destination' => 'Victoria Falls',
                'distance_km' => 440,
                'duration_minutes' => 360,
                'base_fare' => 30,
                'status' => 'active',
            ]);
            $this->seedStops($route, [
                ['name' => 'Bulawayo', 'offset' => 0],
                ['name' => 'Hwange', 'offset' => 180],
                ['name' => 'Victoria Falls', 'offset' => 360],
            ]);

            $ctx->clear();

            return;
        }

        // ── Demo Transport: full configuration ─────────────────────
        $layout = $this->createCoachLayout($tenant, '56-seat luxury coach', 14, 4);
        $vipLayout = $this->createVipLayout($tenant);

        $busA = Bus::create([
            'seat_layout_id' => $layout->id,
            'registration_number' => 'DT-4521',
            'fleet_number' => 'F-01',
            'make' => 'Scania', 'model' => 'Touring', 'year' => 2022,
            'bus_type' => 'coach', 'capacity' => 56,
            'amenities' => ['wifi', 'usb_charging', 'air_conditioning', 'toilet'],
            'accessibility' => ['wheelchair_lift'],
            'status' => 'active',
        ]);
        $busB = Bus::create([
            'seat_layout_id' => $vipLayout->id,
            'registration_number' => 'DT-8873',
            'fleet_number' => 'F-02',
            'make' => 'Volvo', 'model' => 'B8R', 'year' => 2023,
            'bus_type' => 'vip_coach', 'capacity' => 33,
            'amenities' => ['wifi', 'usb_charging', 'air_conditioning', 'toilet', 'refreshments'],
            'accessibility' => ['wheelchair_lift', 'priority_seating'],
            'status' => 'active',
        ]);

        $route = BusRoute::create([
            'name' => 'Harare → Bulawayo Express',
            'origin' => 'Harare',
            'destination' => 'Bulawayo',
            'distance_km' => 440,
            'duration_minutes' => 330,
            'base_fare' => 25,
            'status' => 'active',
        ]);
        $this->seedStops($route, [
            ['name' => 'Harare Showgrounds', 'offset' => 0],
            ['name' => 'Chitungwiza', 'offset' => 25],
            ['name' => 'Kwekwe', 'offset' => 150],
            ['name' => 'Gweru', 'offset' => 210],
            ['name' => 'Bulawayo', 'offset' => 330],
        ]);

        // Segment fares: shorter legs cost less than full route.
        $route->fares()->createMany([
            ['tenant_id' => $tenant->id, 'from_sequence' => 0, 'to_sequence' => 2, 'passenger_type' => 'adult', 'amount' => 15],
            ['tenant_id' => $tenant->id, 'from_sequence' => 2, 'to_sequence' => 4, 'passenger_type' => 'adult', 'amount' => 15],
            ['tenant_id' => $tenant->id, 'from_sequence' => 0, 'to_sequence' => 4, 'passenger_type' => 'adult', 'amount' => 25],
            ['tenant_id' => $tenant->id, 'from_sequence' => null, 'to_sequence' => null, 'passenger_type' => 'child', 'amount' => 12],
            ['tenant_id' => $tenant->id, 'from_sequence' => null, 'to_sequence' => null, 'passenger_type' => 'senior', 'amount' => 20],
        ]);

        $schedule = Schedule::create([
            'route_id' => $route->id,
            'bus_id' => $busA->id,
            'name' => 'Morning Express',
            'departure_time' => '07:30',
            'days_of_week' => [1, 2, 3, 4, 5, 6],
            'start_date' => now()->toDateString(),
            'end_date' => now()->addMonths(6)->toDateString(),
            'status' => 'active',
        ]);
        $vipSchedule = Schedule::create([
            'route_id' => $route->id,
            'bus_id' => $busB->id,
            'name' => 'Afternoon VIP',
            'departure_time' => '14:00',
            'days_of_week' => [1, 3, 5, 7],
            'start_date' => now()->toDateString(),
            'end_date' => now()->addMonths(6)->toDateString(),
            'fare_override' => 40,
            'status' => 'active',
        ]);

        // Generate the next 45 days of trips.
        $tripService = app(TripService::class);
        $tripService->generateFromSchedule($schedule, now(), now()->addDays(45));
        $tripService->generateFromSchedule($vipSchedule, now(), now()->addDays(45));

        Promotion::create([
            'code' => 'LAUNCH10',
            'name' => 'Launch promotion — 10% off',
            'type' => 'percent',
            'value' => 10,
            'usage_limit' => 500,
            'per_customer_limit' => 3,
            'ends_at' => now()->addMonths(3),
            'status' => 'active',
            'applies_to' => 'all',
        ]);

        // A couple of seeded bookings on tomorrow's trip for realism.
        $tomorrowTrip = Trip::where('tenant_id', $tenant->id)
            ->where('departure_at', '>', now())
            ->orderBy('departure_at')->first();

        if ($tomorrowTrip) {
            $bookings = app(BookingService::class);
            $payment = app(PaymentService::class);

            $booking = $bookings->create([
                'trip_id' => $tomorrowTrip->id,
                'boarding_stop_id' => $route->stops()->where('sequence', 0)->value('id'),
                'dropoff_stop_id' => $route->stops()->where('sequence', 4)->value('id'),
                'boarding_sequence' => 0,
                'dropoff_sequence' => 4,
                'contact_name' => 'Tendai Moyo',
                'contact_email' => 'tendai@example.com',
                'contact_phone' => '+263771234567',
                'seat_preference' => 'together',
                'payment_method' => null,
                'passengers' => [
                    ['first_name' => 'Tendai', 'last_name' => 'Moyo', 'passenger_type' => 'adult', 'phone' => '+263771234567'],
                    ['first_name' => 'Rudo', 'last_name' => 'Moyo', 'passenger_type' => 'adult'],
                ],
            ]);

            $cashPayment = $payment->initiate($booking->fresh(), 'cash');
            $payment->confirmCash($cashPayment, User::where('email', 'finance@'.$tenant->slug.'.example.com')->first());
            app(TicketService::class)->issueFor($booking->fresh());
        }

        $ctx->clear();
    }

    /** Standard 2+2 coach: rows × cols, first row has driver area. */
    private function createCoachLayout(Tenant $tenant, string $name, int $rows, int $cols): SeatLayout
    {
        $layout = SeatLayout::create([
            'name' => $name,
            'description' => "{$rows} rows × {$cols} columns",
            'decks' => 1, 'rows' => $rows, 'cols' => $cols,
            'status' => 'published',
        ]);

        $seats = [];
        $n = 1;
        for ($row = 1; $row <= $rows; $row++) {
            for ($col = 1; $col <= $cols; $col++) {
                if ($row === 1 && $col === 4) {
                    $type = 'driver';
                    $label = 'DRV';
                    $position = 'none';
                } elseif ($row === $rows && $col >= 3) {
                    $type = 'toilet';
                    $label = 'WC'.$col;
                    $position = 'none';
                } else {
                    $type = 'standard';
                    $label = (string) $n;
                    $position = in_array($col, [1, 4], true) ? 'window' : (in_array($col, [2, 3], true) ? 'aisle' : 'none');
                    $n++;
                }

                $seats[] = [
                    'deck' => 1, 'row' => $row, 'col' => $col, 'label' => $label,
                    'type' => $type, 'position' => $position,
                    'is_bookable' => ! in_array($type, SeatLayoutSeat::NON_BOOKABLE_TYPES, true),
                    'sort_order' => $row * 10 + $col,
                ];
            }
        }

        $layout->seats()->createMany(collect($seats)->map(fn ($s) => $s + ['tenant_id' => $tenant->id]));

        return $layout;
    }

    /** VIP 2+1 layout with premium front rows. */
    private function createVipLayout(Tenant $tenant): SeatLayout
    {
        $layout = SeatLayout::create([
            'name' => 'VIP 2+1 coach',
            'description' => '33-seat VIP configuration',
            'decks' => 1, 'rows' => 11, 'cols' => 3,
            'status' => 'published',
        ]);

        $seats = [];
        $n = 1;
        for ($row = 1; $row <= 11; $row++) {
            for ($col = 1; $col <= 3; $col++) {
                $type = $row <= 3 ? 'vip' : 'standard';
                $seats[] = [
                    'deck' => 1, 'row' => $row, 'col' => $col, 'label' => "V{$n}",
                    'type' => $type,
                    'position' => $col === 1 ? 'window' : ($col === 3 ? 'window' : 'aisle'),
                    'price_modifier' => $row <= 3 ? 10 : 0,
                    'is_bookable' => true,
                    'sort_order' => $row * 10 + $col,
                ];
                $n++;
            }
        }

        $layout->seats()->createMany(collect($seats)->map(fn ($s) => $s + ['tenant_id' => $tenant->id]));

        return $layout;
    }

    private function seedStops(BusRoute $route, array $stops): void
    {
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
