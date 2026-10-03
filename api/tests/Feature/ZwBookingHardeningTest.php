<?php

namespace Tests\Feature;

use App\Models\Location;
use App\Models\SeatLayout;
use App\Models\TripSeat;
use App\Services\SeatLayoutGenerator;
use App\Services\TripService;
use Database\Seeders\ZimbabweLocationsSeeder;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ZwBookingHardeningTest extends TestCase
{
    public function test_inactive_locations_are_excluded_from_public_search(): void
    {
        $this->seed(ZimbabweLocationsSeeder::class);
        Location::where('name', 'Harare')->update(['is_active' => false]);
        $tenant = $this->makeTenant();

        $names = collect($this->publicGet($tenant, '/api/v1/public/locations')->json('data'))->pluck('name');
        $this->assertNotContains('Harare', $names);
    }

    public function test_admin_can_deactivate_and_reactivate_a_location(): void
    {
        $this->seed(ZimbabweLocationsSeeder::class);
        $tenant = $this->makeTenant();
        Sanctum::actingAs($this->makeUser($tenant, 'tenant_admin'));
        $loc = Location::where('name', 'Kwekwe')->first();

        $this->patchJson("/api/v1/locations/{$loc->id}", ['is_active' => false])->assertOk();
        $names = collect($this->publicGet($tenant, '/api/v1/public/locations?q=kwe')->json('data'))->pluck('name');
        $this->assertNotContains('Kwekwe', $names);

        $this->patchJson("/api/v1/locations/{$loc->id}", ['is_active' => true])->assertOk();
        $names = collect($this->publicGet($tenant, '/api/v1/public/locations?q=kwe')->json('data'))->pluck('name');
        $this->assertContains('Kwekwe', $names);
    }

    public function test_layout_duplicate_copies_all_seats_as_draft(): void
    {
        $tenant = $this->makeTenant();
        Sanctum::actingAs($this->makeUser($tenant, 'tenant_admin'));
        $layout = SeatLayout::create([
            'name' => 'orig', 'decks' => 1, 'rows' => 0, 'cols' => 0,
            'status' => 'published', 'tenant_id' => $tenant->id,
        ]);
        $layout->update(['status' => 'draft']);
        $layout = app(SeatLayoutGenerator::class)->generate($layout, [
            'rows' => 4, 'left_seats' => 2, 'right_seats' => 2, 'rear_row_seats' => 5,
        ]);
        $layout->update(['status' => 'published']);

        $copy = $this->postJson("/api/v1/seat-layouts/{$layout->id}/duplicate")->assertCreated()->json();
        $this->assertSame('draft', $copy['status']);
        $this->assertCount(21, $copy['seats']); // 4×4 + 5
        $this->assertNotSame($layout->id, $copy['id']);
    }

    public function test_changing_bus_layout_never_alters_historical_trip_seats(): void
    {
        $tenant = $this->makeTenant();
        $trip = $this->makeTrip($tenant); // 12-seat (4×3) layout materialised
        $original = TripSeat::where('trip_id', $trip->id)->pluck('label')->sort()->values()->all();
        $this->assertCount(12, $original);

        // Bus gets a brand-new 25-seat layout.
        $layout = SeatLayout::create([
            'name' => 'v2', 'decks' => 1, 'rows' => 0, 'cols' => 0,
            'status' => 'draft', 'tenant_id' => $tenant->id,
        ]);
        $layout = app(SeatLayoutGenerator::class)->generate($layout, [
            'rows' => 5, 'left_seats' => 2, 'right_seats' => 2, 'rear_row_seats' => 5,
        ]);
        $layout->update(['status' => 'published']);
        $trip->bus->update(['seat_layout_id' => $layout->id]);

        // Historical trip snapshot is untouched.
        $after = TripSeat::where('trip_id', $trip->id)->pluck('label')->sort()->values()->all();
        $this->assertSame($original, $after);

        // A NEW trip on the same bus inherits the new configuration.
        $newTrip = app(TripService::class)->create([
            'route_id' => $trip->route_id, 'bus_id' => $trip->bus_id,
            'departure_at' => now()->addDays(5)->setTime(9, 0),
        ]);
        $this->assertSame(25, TripSeat::where('trip_id', $newTrip->id)->count());
    }

    public function test_numeric_label_style_produces_sequential_numbers(): void
    {
        $layout = SeatLayout::create([
            'name' => 'numeric', 'decks' => 1, 'rows' => 0, 'cols' => 0,
            'status' => 'draft', 'tenant_id' => $this->makeTenant()->id,
        ]);
        $layout = app(SeatLayoutGenerator::class)->generate($layout, [
            'rows' => 3, 'left_seats' => 2, 'right_seats' => 2, 'rear_row_seats' => 5,
            'label_style' => 'numeric',
        ]);
        $this->assertSame(['1', '2', '3', '4'], $layout->seats->take(4)->pluck('label')->all());
        $this->assertSame('17', $layout->seats->sortBy('sort_order')->last()->label);
    }

    public function test_generated_seats_carry_side_and_position_metadata(): void
    {
        $layout = SeatLayout::create([
            'name' => 'meta', 'decks' => 1, 'rows' => 0, 'cols' => 0,
            'status' => 'draft', 'tenant_id' => $this->makeTenant()->id,
        ]);
        $layout = app(SeatLayoutGenerator::class)->generate($layout, [
            'rows' => 2, 'left_seats' => 2, 'right_seats' => 2, 'rear_row_seats' => 5,
        ]);
        $this->assertSame('left', $layout->seats->firstWhere('label', '1A')->side);
        $this->assertSame('right', $layout->seats->firstWhere('label', '1E')->side);
        $this->assertSame('window', $layout->seats->firstWhere('label', '1A')->position);
        $this->assertSame('center', $layout->seats->firstWhere('label', '3C')->side); // rear middle
    }

    public function test_passenger_count_rules_enforced_server_side(): void
    {
        $tenant = $this->makeTenant(['settings' => ['booking_rules' => ['max_seats_per_booking' => 2], 'payment_methods' => ['cash']]]);
        $trip = $this->makeTrip($tenant);

        // Zero passengers
        $payload = $this->bookingPayload($trip);
        $payload['passengers'] = [];
        $this->publicPost($tenant, '/api/v1/public/bookings', $payload)->assertStatus(422);

        // Over tenant max
        $payload['passengers'] = array_fill(0, 3, ['first_name' => 'P', 'last_name' => 'X']);
        $this->publicPost($tenant, '/api/v1/public/bookings', $payload)->assertStatus(422);
    }

    public function test_out_of_bounds_seat_cells_rejected_on_save(): void
    {
        $tenant = $this->makeTenant();
        Sanctum::actingAs($this->makeUser($tenant, 'tenant_admin'));

        $this->postJson('/api/v1/seat-layouts', [
            'name' => 'bad', 'decks' => 1, 'rows' => 2, 'cols' => 2,
            'seats' => [[
                'deck' => 1, 'row' => 99, 'col' => 1, 'label' => 'X1', 'type' => 'standard',
            ]],
        ])->assertStatus(422);
    }

    public function test_seats_endpoint_exposes_grouped_seat_map_for_mobile(): void
    {
        $tenant = $this->makeTenant();
        $trip = $this->makeTrip($tenant);

        $res = $this->publicGet($tenant, "/api/v1/public/trips/{$trip->id}/seats?boarding=0&dropoff=2");
        $res->assertOk()->assertJsonStructure(['seat_map' => ['rows' => [['row', 'seats']]]]);
        $this->assertCount(3, $res->json('seat_map.rows')); // 3 rows in the 4×3 test layout
    }
}
