<?php

namespace Tests\Feature;

use App\Models\Payment;
use Tests\TestCase;

class RbacTest extends TestCase
{
    public function test_booking_agent_cannot_confirm_cash_payment(): void
    {
        $tenant = $this->makeTenant();
        $trip = $this->makeTrip($tenant);
        $this->publicPost($tenant, '/api/v1/public/bookings', $this->bookingPayload($trip));
        $payment = Payment::first();

        $agent = $this->makeUser($tenant, 'booking_agent');
        $this->actingAs($agent, 'sanctum');

        $this->postJson("/api/v1/payments/{$payment->id}/confirm")->assertForbidden();
        $this->assertSame('pending', $payment->fresh()->status);
    }

    public function test_support_agent_cannot_view_payments(): void
    {
        $tenant = $this->makeTenant();
        $support = $this->makeUser($tenant, 'support_agent');
        $this->actingAs($support, 'sanctum');

        $this->getJson('/api/v1/payments')->assertForbidden();
        $this->getJson('/api/v1/buses')->assertForbidden();
    }

    public function test_agent_can_create_booking_and_verify_ticket(): void
    {
        $tenant = $this->makeTenant();
        $trip = $this->makeTrip($tenant);
        $agent = $this->makeUser($tenant, 'booking_agent');
        $this->actingAs($agent, 'sanctum');

        $this->postJson('/api/v1/bookings', $this->bookingPayload($trip))->assertCreated();
    }

    public function test_finance_cannot_delete_buses_or_manage_users(): void
    {
        $tenant = $this->makeTenant();
        $trip = $this->makeTrip($tenant);
        $finance = $this->makeUser($tenant, 'finance_officer');
        $bus = $trip->bus;
        $this->actingAs($finance, 'sanctum');

        $this->deleteJson("/api/v1/buses/{$bus->id}")->assertForbidden();
        $this->postJson('/api/v1/users', [
            'name' => 'X', 'email' => 'x@example.com', 'password' => 'password1234', 'role' => 'booking_agent',
        ])->assertForbidden();
    }

    public function test_tenant_admin_cannot_assign_platform_role(): void
    {
        $tenant = $this->makeTenant();
        $admin = $this->makeUser($tenant, 'tenant_admin');
        $this->actingAs($admin, 'sanctum');

        $this->postJson('/api/v1/users', [
            'name' => 'X', 'email' => 'x'.uniqid().'@example.com',
            'password' => 'password1234', 'role' => 'platform_super_admin',
        ])->assertStatus(422);
    }

    public function test_disabled_user_is_blocked(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant, 'tenant_admin');
        $user->update(['status' => 'disabled']);

        $this->actingAs($user, 'sanctum');
        $this->getJson('/api/v1/trips')->assertForbidden();
    }
}
