<?php

namespace Tests\Feature;

use App\Models\Bus;
use App\Models\Payment;
use App\Models\Trip;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    public function test_tenant_user_cannot_list_other_tenants_resources(): void
    {
        $tenantA = $this->makeTenant(['slug' => 'alpha']);
        $tenantB = $this->makeTenant(['slug' => 'beta']);

        $this->makeTrip($tenantA);
        $userB = $this->makeUser($tenantB, 'tenant_admin');

        $this->actingAs($userB, 'sanctum');
        $response = $this->getJson('/api/v1/trips');
        $response->assertOk();
        $this->assertCount(0, $response->json('data'));
    }

    public function test_tenant_user_cannot_read_other_tenants_models(): void
    {
        $tenantA = $this->makeTenant(['slug' => 'alpha']);
        $tenantB = $this->makeTenant(['slug' => 'beta']);

        $tripA = $this->makeTrip($tenantA);
        $busA = Bus::withoutTenantScope()->firstWhere('tenant_id', $tenantA->id);
        $userB = $this->makeUser($tenantB, 'tenant_admin');

        $this->actingAs($userB, 'sanctum');

        $this->getJson("/api/v1/trips/{$tripA->id}")->assertNotFound();
        $this->getJson("/api/v1/buses/{$busA->id}")->assertNotFound();

        // Direct update attempts hit the same scope → 404, never a write.
        $this->patchJson("/api/v1/buses/{$busA->id}", ['status' => 'retired'])->assertNotFound();
        $this->assertNotEquals('retired', $busA->fresh()->status);
    }

    public function test_tenant_user_cannot_read_other_tenants_booking(): void
    {
        $tenantA = $this->makeTenant(['slug' => 'alpha']);
        $tenantB = $this->makeTenant(['slug' => 'beta']);

        $tripA = $this->makeTrip($tenantA);
        $booking = $this->publicPost($tenantA, '/api/v1/public/bookings', $this->bookingPayload($tripA))->json('data.id');
        $userB = $this->makeUser($tenantB, 'tenant_admin');

        $this->actingAs($userB, 'sanctum');
        $this->getJson("/api/v1/bookings/{$booking}")->assertNotFound();
    }

    public function test_tenant_user_cannot_read_other_tenants_payments(): void
    {
        $tenantA = $this->makeTenant(['slug' => 'alpha']);
        $tenantB = $this->makeTenant(['slug' => 'beta']);

        $tripA = $this->makeTrip($tenantA);
        $this->publicPost($tenantA, '/api/v1/public/bookings', $this->bookingPayload($tripA));
        $paymentA = Payment::withoutTenantScope()->firstWhere('tenant_id', $tenantA->id);

        $userB = $this->makeUser($tenantB, 'finance_officer');
        $this->actingAs($userB, 'sanctum');

        $this->getJson("/api/v1/payments/{$paymentA->id}")->assertNotFound();
        $this->getJson('/api/v1/payments')->assertJsonCount(0, 'data');
    }

    public function test_public_booking_never_leaks_across_hosts(): void
    {
        $tenantA = $this->makeTenant(['slug' => 'alpha']);
        $tenantB = $this->makeTenant(['slug' => 'beta']);

        $tripA = $this->makeTrip($tenantA);

        // Trip A's ID through tenant B's host → 404 (scoped).
        $this->publicGet($tenantB, "/api/v1/public/trips/{$tripA->id}")->assertNotFound();
    }

    public function test_public_booking_rejects_other_tenants_trip(): void
    {
        $tenantA = $this->makeTenant(['slug' => 'alpha']);
        $tenantB = $this->makeTenant(['slug' => 'beta']);

        $tripA = $this->makeTrip($tenantA);
        $tripB = $this->makeTrip($tenantB);
        $payload = $this->bookingPayload($tripA);

        // Tenant B's host cannot book trip A — the trip resolves under B's scope → 404.
        $this->publicPost($tenantB, '/api/v1/public/bookings', $payload)->assertNotFound();
    }

    public function test_platform_admin_sees_all_tenants_but_tenant_users_do_not(): void
    {
        $tenantA = $this->makeTenant(['slug' => 'alpha']);
        $tenantB = $this->makeTenant(['slug' => 'beta']);
        $admin = $this->makePlatformAdmin();
        $userA = $this->makeUser($tenantA, 'tenant_owner');

        $this->actingAs($admin, 'sanctum');
        $this->getJson('/api/v1/platform/tenants')->assertOk()->assertJsonCount(2, 'data');

        $this->actingAs($userA, 'sanctum');
        $this->getJson('/api/v1/platform/tenants')->assertForbidden();
    }

    public function test_suspended_tenant_blocks_all_access(): void
    {
        $tenant = $this->makeTenant(['status' => 'suspended']);
        $user = $this->makeUser($tenant, 'tenant_owner');
        $this->makeTrip($tenant);

        $this->actingAs($user, 'sanctum');
        $this->getJson('/api/v1/trips')->assertForbidden();

        $this->publicGet($tenant, '/api/v1/public/endpoints')->assertNotFound();
    }
}
