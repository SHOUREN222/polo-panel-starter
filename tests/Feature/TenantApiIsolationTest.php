<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TenantApiIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        $this->endTenancy();

        foreach (Tenant::all() as $tenant) {
            $tenant->delete();
        }

        parent::tearDown();
    }

    private function tenant(string $id): Tenant
    {
        $tenant = Tenant::create(['id' => $id]);
        $tenant->domains()->create(['domain' => $id]);

        return $tenant;
    }

    private function endTenancy(): void
    {
        // Feature tests reuse the same application instance across requests.
        // Production PHP requests do not, so clear a bearer-authenticated user
        // before emulating the next host/request in this test process.
        Auth::forgetGuards();

        if (tenant()) {
            tenancy()->end();
        }
    }

    private function register(Tenant $tenant, string $email = 'api@example.com'): string
    {
        $response = $this->postJson("http://{$tenant->id}.example.test/api/register", [
            'name' => 'API customer',
            'email' => $email,
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertCreated();

        $token = (string) $response->json('data.token');
        $this->assertNotSame('', $token);
        $this->endTenancy();

        return $token;
    }

    public function test_registration_on_a_subdomain_writes_only_to_that_tenant(): void
    {
        $tenant = $this->tenant('api-register');
        $this->register($tenant);

        $tenant->run(function () {
            $this->assertSame(1, User::where('email', 'api@example.com')->count());
            $this->assertSame(1, DB::table('personal_access_tokens')->count());
        });

        $this->assertDatabaseMissing('users', ['email' => 'api@example.com']);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_a_tenant_token_cannot_authenticate_on_another_site(): void
    {
        $first = $this->tenant('api-first');
        $second = $this->tenant('api-second');
        $token = $this->register($first);

        $this->withToken($token)
            ->getJson('http://api-first.example.test/api/me')
            ->assertOk()
            ->assertJsonPath('data.email', 'api@example.com');
        $this->endTenancy();

        $this->withToken($token)
            ->getJson('http://api-second.example.test/api/me')
            ->assertUnauthorized();
        $this->endTenancy();

        $this->withToken($token)
            ->getJson('http://app.example.test/api/me')
            ->assertUnauthorized();
    }

    public function test_revocation_in_a_tenant_does_not_delete_central_credentials_with_the_same_user_id(): void
    {
        $central = User::factory()->create(['password' => 'central-password']);
        $central->createToken('central');
        DB::table('sessions')->insert([
            'id' => 'central-session',
            'user_id' => $central->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'test',
            'payload' => '{}',
            'last_activity' => now()->timestamp,
        ]);

        $tenant = $this->tenant('api-revoke');
        $tenant->run(function () use ($central) {
            $customer = User::create([
                'name' => 'Tenant customer',
                'email' => 'tenant@example.com',
                'password' => 'old-password',
            ]);
            $this->assertSame($central->id, $customer->id);

            $customer->createToken('tenant');
            DB::table('sessions')->insert([
                'id' => 'tenant-session',
                'user_id' => $customer->id,
                'ip_address' => '127.0.0.1',
                'user_agent' => 'test',
                'payload' => '{}',
                'last_activity' => now()->timestamp,
            ]);

            $customer->update(['password' => 'new-password']);

            $this->assertDatabaseMissing('sessions', ['user_id' => $customer->id]);
            $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $customer->id]);
        });

        $this->assertDatabaseHas('sessions', ['id' => 'central-session', 'user_id' => $central->id]);
        $this->assertDatabaseHas('personal_access_tokens', ['tokenable_id' => $central->id]);
    }

    public function test_a_tenant_api_order_uses_the_shared_plan_but_stays_in_the_tenant_db(): void
    {
        $plan = Plan::create([
            'name' => 'API shared plan',
            'type' => Plan::TYPE_SHARED,
            'group_id' => 1,
            'transfer_enable' => 20 * 1024 * 1024 * 1024,
            'prices' => ['month' => 1500],
            'sell' => true,
        ]);
        $tenant = $this->tenant('api-order');
        $token = $this->register($tenant, 'buyer@example.com');

        $this->withToken($token)
            ->postJson('http://api-order.example.test/api/orders', [
                'plan_id' => $plan->id,
                'period' => 'month',
            ])
            ->assertCreated()
            ->assertJsonPath('data.plan_id', $plan->id);
        $this->endTenancy();

        $tenant->run(function () {
            $this->assertSame(1, Order::count());
            $this->assertSame('buyer@example.com', Order::first()->user->email);
        });
        $this->assertSame(0, Order::count());
    }

    public function test_api_login_rate_limits_are_isolated_between_tenants(): void
    {
        $first = $this->tenant('api-limit-a');
        $second = $this->tenant('api-limit-b');

        foreach ([$first, $second] as $tenant) {
            $tenant->run(fn () => User::create([
                'name' => 'Same address',
                'email' => 'same@example.com',
                'password' => 'password123',
            ]));
        }

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('http://api-limit-a.example.test/api/login', [
                'email' => 'same@example.com',
                'password' => 'wrong',
            ])->assertUnauthorized();
            $this->endTenancy();
        }

        $this->postJson('http://api-limit-b.example.test/api/login', [
            'email' => 'same@example.com',
            'password' => 'password123',
        ])->assertOk();
    }
}
