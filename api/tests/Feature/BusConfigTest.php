<?php

namespace Tests\Feature;

use App\Models\Bus;
use App\Models\BusRoute;
use App\Models\SeatLayout;
use App\Models\TripSeat;
use App\Services\TripService;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Bus capacity is ALWAYS derived from its seat layout — never NULL, never client-supplied. */
class BusConfigTest extends TestCase
{
    private const ZW = ['rows' => 11, 'left_seats' => 2, 'right_seats' => 2, 'rear_row_seats' => 5];

    public function test_create_bus_with_seating_config_generates_layout_and_capacity(): void
    {
        $tenant = $this->makeTenant();
        Sanctum::actingAs($this->makeUser($tenant, 'tenant_admin'));

        $bus = $this->postJson('/api/v1/buses', [
            'registration_number' => 'ZW-49',
            'bus_type' => 'coach',
            'seating_config' => self::ZW,
        ])->assertCreated()->json();

        $this->assertSame(49, $bus['capacity']);           // 11×4 + 5
        $bus = Bus::find($bus['id']);
        $this->assertNotNull($bus->seat_layout_id);
        $this->assertSame(49, $bus->seatLayout->seats()->count());
        $this->assertSame('1A', $bus->seatLayout->seats()->where('is_bookable', true)->orderBy('sort_order')->first()->label);
    }

    public function test_create_bus_without_seating_is_rejected_cleanly(): void
    {
        Sanctum::actingAs($this->makeUser($this->makeTenant(), 'tenant_admin'));

        $res = $this->postJson('/api/v1/buses', [
            'registration_number' => 'NO-SEATS',
            'bus_type' => 'coach',
        ]);

        $res->assertStatus(422)->assertJsonValidationErrors(['seat_layout_id']);
        // Friendly message — never a raw SQLSTATE.
        $this->assertStringNotContainsString('SQLSTATE', json_encode($res->json()));
    }

    public function test_edit_bus_generates_new_layout_version_and_updates_capacity(): void
    {
        $tenant = $this->makeTenant();
        Sanctum::actingAs($this->makeUser($tenant, 'tenant_admin'));

        $bus = Bus::find($this->postJson('/api/v1/buses', [
            'registration_number' => 'ED-1', 'bus_type' => 'coach',
            'seating_config' => self::ZW,
        ])->assertCreated()->json('id'));
        $oldLayout = $bus->seat_layout_id;

        $res = $this->patchJson("/api/v1/buses/{$bus->id}", [
            'registration_number' => 'ED-1', 'bus_type' => 'coach',
            'seating_config' => ['rows' => 12, 'left_seats' => 2, 'right_seats' => 2, 'rear_row_seats' => 5],
        ])->assertOk()->json();

        $this->assertSame(53, $res['capacity']); // 12×4 + 5
        $bus->refresh();
        $this->assertNotSame($oldLayout, $bus->seat_layout_id);   // versioned, not mutated
        $this->assertSame(49, SeatLayout::find($oldLayout)->seats()->count()); // old layout intact
    }

    public function test_alternative_configurations_supported(): void
    {
        $tenant = $this->makeTenant();
        Sanctum::actingAs($this->makeUser($tenant, 'tenant_admin'));

        $cases = [
            [2, 1, 0, 30, '2+1'],
            [1, 1, 0, 20, '1+1'],
            [3, 2, 0, 50, '3+2'],
        ];
        foreach ($cases as [$l, $r, $rear, $expected, $tag]) {
            $bus = $this->postJson('/api/v1/buses', [
                'registration_number' => "CFG-{$tag}", 'bus_type' => 'coach',
                'seating_config' => ['rows' => 10, 'left_seats' => $l, 'right_seats' => $r, 'rear_row_seats' => $rear],
            ])->assertCreated()->json();
            $this->assertSame($expected, $bus['capacity'], "{$tag} wrong capacity");
        }
    }

    public function test_client_supplied_capacity_is_never_trusted(): void
    {
        $tenant = $this->makeTenant();
        Sanctum::actingAs($this->makeUser($tenant, 'tenant_admin'));

        $bus = $this->postJson('/api/v1/buses', [
            'registration_number' => 'SNEAKY', 'bus_type' => 'coach',
            'capacity' => 999, // ignored — 'capacity' is not fillable-validated
            'seating_config' => self::ZW,
        ])->assertCreated()->json();

        $this->assertSame(49, $bus['capacity']);
    }

    public function test_layout_swap_keeps_historical_trip_intact(): void
    {
        $tenant = $this->makeTenant();
        Sanctum::actingAs($this->makeUser($tenant, 'tenant_admin'));

        $bus = Bus::find($this->postJson('/api/v1/buses', [
            'registration_number' => 'HIST', 'bus_type' => 'coach',
            'seating_config' => self::ZW,
        ])->assertCreated()->json('id'));

        // Trip on the 49-seat layout.
        $route = BusRoute::create([
            'name' => 'A→B', 'origin' => 'A', 'destination' => 'B', 'base_fare' => 10,
            'distance_km' => 100, 'duration_minutes' => 60, 'status' => 'active', 'tenant_id' => $tenant->id,
        ]);
        $route->stops()->createMany([
            ['name' => 'A', 'sequence' => 0, 'can_board' => true, 'can_alight' => false, 'offset_minutes' => 0, 'tenant_id' => $tenant->id],
            ['name' => 'B', 'sequence' => 1, 'can_board' => false, 'can_alight' => true, 'offset_minutes' => 60, 'tenant_id' => $tenant->id],
        ]);
        $trip = app(TripService::class)->create([
            'route_id' => $route->id, 'bus_id' => $bus->id,
            'departure_at' => now()->addDay()->setTime(8, 0),
        ]);
        $this->assertSame(49, TripSeat::where('trip_id', $trip->id)->count());

        // Swap to a bigger config — the trip keeps its 49-seat snapshot.
        $this->patchJson("/api/v1/buses/{$bus->id}", [
            'registration_number' => 'HIST', 'bus_type' => 'coach',
            'seating_config' => ['rows' => 12, 'left_seats' => 2, 'right_seats' => 2, 'rear_row_seats' => 5],
        ])->assertOk();

        $this->assertSame(49, TripSeat::where('trip_id', $trip->id)->count());
        $this->assertSame(53, $bus->fresh()->capacity);
    }

    public function test_verify_and_repair_commands(): void
    {
        $tenant = $this->makeTenant();
        Sanctum::actingAs($this->makeUser($tenant, 'tenant_admin'));

        $bus = Bus::find($this->postJson('/api/v1/buses', [
            'registration_number' => 'FIX-1', 'bus_type' => 'coach',
            'seating_config' => self::ZW,
        ])->assertCreated()->json('id'));

        $this->assertSame(0, Artisan::call('transithub:verify-buses'));

        // Simulate the legacy broken state directly (bypasses app logic).
        \DB::table('buses')->where('id', $bus->id)->update(['capacity' => 0]);
        $this->assertSame(1, Artisan::call('transithub:verify-buses'));

        // Repair derives capacity from the layout.
        $this->assertSame(0, Artisan::call('transithub:repair-bus-seats'));
        $this->assertSame(49, $bus->fresh()->capacity);
        $this->assertSame(0, Artisan::call('transithub:verify-buses'));
    }

    public function test_no_duplicate_seats_on_double_generation(): void
    {
        $tenant = $this->makeTenant();
        Sanctum::actingAs($this->makeUser($tenant, 'tenant_admin'));

        $bus = Bus::find($this->postJson('/api/v1/buses', [
            'registration_number' => 'DUP', 'bus_type' => 'coach',
            'seating_config' => self::ZW,
        ])->assertCreated()->json('id'));

        // Re-save with the same config → new layout (versioned), never
        // duplicated labels inside any single layout.
        $this->patchJson("/api/v1/buses/{$bus->id}", [
            'registration_number' => 'DUP', 'bus_type' => 'coach',
            'seating_config' => self::ZW,
        ])->assertOk();

        $labels = $bus->fresh()->seatLayout->seats->pluck('label');
        $this->assertSame(49, $labels->count());
        $this->assertSame(49, $labels->unique()->count());
    }
}
