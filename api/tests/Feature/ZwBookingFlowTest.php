<?php

namespace Tests\Feature;

use App\Models\Location;
use App\Models\Payment;
use App\Models\SeatLayout;
use App\Models\TripSeat;
use App\Services\SeatLayoutGenerator;
use App\Services\TripService;
use Database\Seeders\ZimbabweLocationsSeeder;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Zimbabwe rollout: locations dataset, searchable From/To validation,
 * numeric passenger rules, multi-seat bookings, generated bus layouts.
 */
class ZwBookingFlowTest extends TestCase
{
    public function test_zimbabwe_locations_are_seeded(): void
    {
        $this->seed(ZimbabweLocationsSeeder::class);

        $this->assertGreaterThanOrEqual(30, Location::count());
        $this->assertDatabaseHas('locations', ['name' => 'Harare', 'country_code' => 'ZW']);
        // The source list duplicated Beitbridge — slug uniqueness keeps it one row.
        $this->assertSame(1, Location::where('name', 'Beitbridge')->count());
    }

    public function test_public_location_search_is_case_insensitive_partial_match(): void
    {
        $this->seed(ZimbabweLocationsSeeder::class);
        $tenant = $this->makeTenant();

        $data = $this->publicGet($tenant, '/api/v1/public/locations?q=bul')->json('data');
        $this->assertContains('Bulawayo', collect($data)->pluck('name'));
        $this->assertContains('Harare', collect($this->publicGet($tenant, '/api/v1/public/locations?q=har')->json('data'))->pluck('name'));
        $this->assertNotEmpty($this->publicGet($tenant, '/api/v1/public/locations')->json('data'));
    }

    public function test_search_rejects_same_origin_and_destination(): void
    {
        $tenant = $this->makeTenant();

        $this->publicGet($tenant, '/api/v1/public/trips/search?from=Harare&to=harare&date='.now()->addDay()->toDateString())
            ->assertStatus(422)
            ->assertJsonValidationErrors(['to']);
    }

    public function test_booking_rejects_duplicate_seat_ids(): void
    {
        $tenant = $this->makeTenant();
        $trip = $this->makeTrip($tenant);
        $seatIds = TripSeat::where('trip_id', $trip->id)->limit(1)->pluck('id');
        $payload = $this->bookingPayload($trip);
        $payload['trip_seat_ids'] = [$seatIds[0], $seatIds[0]];
        $payload['passengers'][] = ['first_name' => 'Dupe', 'last_name' => 'Seat'];

        $this->publicPost($tenant, '/api/v1/public/bookings', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('trip_seat_ids.1');
    }

    public function test_booking_validates_seat_passenger_count_mismatch(): void
    {
        $tenant = $this->makeTenant();
        $trip = $this->makeTrip($tenant);
        $payload = $this->bookingPayload($trip);
        $payload['trip_seat_ids'] = TripSeat::where('trip_id', $trip->id)->limit(3)->pluck('id')->all();

        $this->publicPost($tenant, '/api/v1/public/bookings', $payload)->assertStatus(422);
    }

    public function test_multi_seat_booking_prices_and_tickets_per_seat(): void
    {
        $tenant = $this->makeTenant();
        $trip = $this->makeTrip($tenant);
        $payload = $this->bookingPayload($trip);
        $payload['trip_seat_ids'] = TripSeat::where('trip_id', $trip->id)->limit(4)->pluck('id')->all();
        $payload['passengers'] = collect(range(1, 4))->map(fn ($i) => [
            'first_name' => 'P'.$i, 'last_name' => 'Family',
        ])->all();

        $booking = $this->publicPost($tenant, '/api/v1/public/bookings', $payload)->assertCreated()->json('data');

        $this->assertCount(4, $booking['seats']);
        $this->assertCount(4, $booking['passengers']);
        // 4 seats × $25 fare = $100 — payment carries the full booking.
        $this->assertEquals(100.0, (float) $booking['total']);
        $payment = Payment::where('booking_id', $booking['id'])->first();
        $this->assertEquals(100.0, (float) $payment->amount);
    }

    public function test_seat_layout_generator_supports_zw_configurations(): void
    {
        $tenant = $this->makeTenant();
        $cases = [
            // rows, left, right, rear → expected capacity
            [11, 2, 2, 5, 49],  // standard ZW coach
            [10, 2, 1, 0, 30],  // 2+1 luxury
            [12, 1, 1, 4, 28],  // 1+1 VIP + rear 4
            [8, 3, 2, 6, 46],   // 3+2 high capacity + rear 6
        ];

        $gen = app(SeatLayoutGenerator::class);
        foreach ($cases as [$rows, $left, $right, $rear, $cap]) {
            $layout = SeatLayout::create([
                'name' => "t-{$rows}-{$left}-{$right}-{$rear}", 'decks' => 1,
                'rows' => 0, 'cols' => 0, 'status' => 'draft', 'tenant_id' => $tenant->id,
            ]);
            $layout = $gen->generate($layout, [
                'rows' => $rows, 'left_seats' => $left, 'right_seats' => $right, 'rear_row_seats' => $rear,
            ]);
            $this->assertSame($cap, $layout->seats->count(), "{$left}+{$right} r{$rows} rear{$rear}");
            $this->assertSame($cap, $layout->seats->pluck('label')->unique()->count(), 'labels unique');
        }

        // ZW standard spot checks: rear row of 5, window positions on outer cols.
        $zw = $gen->generate(SeatLayout::create([
            'name' => 'zw-spot', 'decks' => 1, 'rows' => 0, 'cols' => 0, 'status' => 'draft', 'tenant_id' => $tenant->id,
        ]), ['rows' => 11, 'left_seats' => 2, 'right_seats' => 2, 'rear_row_seats' => 5]);
        $this->assertCount(5, $zw->seats->where('row', 12));
        $this->assertSame(2, $zw->seats->where('row', 1)->where('position', 'window')->count());
    }

    public function test_published_layout_cannot_be_regenerated(): void
    {
        $layout = SeatLayout::create([
            'name' => 'frozen', 'decks' => 1, 'rows' => 0, 'cols' => 0,
            'status' => 'published', 'tenant_id' => $this->makeTenant()->id,
        ]);
        $this->expectException(ValidationException::class);
        app(SeatLayoutGenerator::class)->generate($layout, ['rows' => 2, 'left_seats' => 2, 'right_seats' => 2]);
    }

    public function test_generated_layout_reaches_bookable_trip_seats(): void
    {
        $tenant = $this->makeTenant();
        $trip = $this->makeTrip($tenant);

        $layout = SeatLayout::create([
            'name' => 'zw-e2e', 'decks' => 1, 'rows' => 0, 'cols' => 0,
            'status' => 'draft', 'tenant_id' => $tenant->id,
        ]);
        $layout = app(SeatLayoutGenerator::class)->generate($layout, [
            'rows' => 5, 'left_seats' => 2, 'right_seats' => 2, 'rear_row_seats' => 5,
        ]);
        $layout->update(['status' => 'published']);

        $bus = $trip->bus;
        $bus->update(['seat_layout_id' => $layout->id]);
        $newTrip = app(TripService::class)->create([
            'route_id' => $trip->route_id,
            'bus_id' => $bus->id,
            'departure_at' => now()->addDays(3)->setTime(8, 0),
        ]);

        // Snapshot: 5 rows × 4 + rear 5 = 25 seats materialised for the trip.
        $this->assertSame(25, TripSeat::where('trip_id', $newTrip->id)->count());
    }

    public function test_staff_can_create_locations(): void
    {
        $tenant = $this->makeTenant();
        Sanctum::actingAs($this->makeUser($tenant, 'tenant_admin'));

        $this->postJson('/api/v1/locations', ['name' => 'Glendale', 'type' => 'town', 'province' => 'Mashonaland Central'])
            ->assertCreated();
        $this->assertDatabaseHas('locations', ['name' => 'Glendale', 'type' => 'town']);
    }
}
