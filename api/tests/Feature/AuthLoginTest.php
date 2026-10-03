<?php

namespace Tests\Feature;

use App\Models\Bus;
use App\Models\Tenant;
use Database\Seeders\DatabaseSeeder;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Deterministic seeded credentials + login edge cases. */
class AuthLoginTest extends TestCase
{
    public function test_platform_admin_can_login_with_seeded_credentials(): void
    {
        $this->seed(DatabaseSeeder::class);

        $res = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@transithub.test',
            'password' => 'password',
        ]);

        $res->assertOk()->assertJsonStructure(['token', 'user']);
        $this->assertTrue($res->json('user.is_platform_user'));
    }

    public function test_tenant_admin_can_login_and_sees_only_own_tenant(): void
    {
        $this->seed(DatabaseSeeder::class);

        $res = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@zimbabweexpress.test',
            'password' => 'password',
        ]);
        $res->assertOk();
        $token = $res->json('token');

        // Dashboard + buses respond for their own tenant.
        $this->getJson('/api/v1/buses', ['Authorization' => "Bearer {$token}"])->assertOk();
        // Cross-tenant isolation holds: ZE admin cannot see BS buses.
        $zeBusIds = Bus::where('tenant_id', Tenant::where('slug', 'zimbabwe-express')->value('id'))->pluck('id');
        $returned = collect($this->getJson('/api/v1/buses', ['Authorization' => "Bearer {$token}"])->json('data'))->pluck('id');
        $this->assertTrue($returned->diff($zeBusIds)->isEmpty());
    }

    public function test_wrong_password_is_rejected(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@transithub.test',
            'password' => 'wrong',
        ])->assertStatus(422)->assertJsonValidationErrors(['email']);
    }

    public function test_unknown_user_is_rejected(): void
    {
        $this->postJson('/api/v1/auth/login', [
            'email' => 'ghost@nowhere.test',
            'password' => 'password',
        ])->assertStatus(422);
    }

    public function test_disabled_user_cannot_login(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant, 'tenant_admin', ['status' => 'disabled']);

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertStatus(422);
    }

    public function test_non_admin_role_has_limited_permissions(): void
    {
        $tenant = $this->makeTenant();
        $agent = $this->makeUser($tenant, 'support_agent');
        Sanctum::actingAs($agent);

        // Support agent cannot see payments (least privilege).
        $this->getJson('/api/v1/payments')->assertForbidden();
        $this->getJson('/api/v1/support-tickets')->assertOk();
    }
}
