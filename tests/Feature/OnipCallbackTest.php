<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Setting;
use App\Models\SubscriptionInstance;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OnipCallbackTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-onip-callback-secret';

    protected function setUp(): void
    {
        parent::setUp();
        Setting::set('onip_callback_secret', self::SECRET);
    }

    protected function tearDown(): void
    {
        if (tenant()) {
            tenancy()->end();
        }
        foreach (Tenant::all() as $tenant) {
            $tenant->delete();
        }

        parent::tearDown();
    }

    /** POST the onip callback with the valid shared secret in the query string. */
    private function hitCallback(array $payload)
    {
        return $this->postJson('/api/callbacks/onip?secret='.self::SECRET, $payload);
    }

    private function instanceWithProxyId(string $proxyId): SubscriptionInstance
    {
        $user = User::factory()->create();
        $plan = Plan::create([
            'name' => '静态 ISP', 'type' => Plan::TYPE_DEDICATED_ISP,
            'transfer_enable' => 1, 'prices' => ['month' => 5500], 'sell' => true,
        ]);

        return $user->subscriptionInstances()->create([
            'plan_id' => $plan->id,
            'transfer_enable' => 1,
            'status' => 'active',
            'expired_at' => now()->addMonth(),
            'meta' => [
                'ip' => '1.1.1.1', 'port' => 1000, 'protocol' => 'socks5',
                'username' => 'old', 'password' => 'old', 'proxy_id' => $proxyId,
            ],
        ]);
    }

    public function test_ip_change_callback_updates_the_matching_instances_meta(): void
    {
        $instance = $this->instanceWithProxyId('SI1');

        $this->hitCallback([
            'type' => 'UPDATE_STATIC_INSTANCE',
            'proxyId' => 'SI1',
            'changeType' => 'IP_CHANGE',
            'data' => [
                'proxyId' => 'SI1', 'ip' => '2.2.2.2', 'port' => 2000,
                'username' => 'new', 'password' => 'new', 'status' => 2,
            ],
        ])->assertOk();

        $instance->refresh();
        $this->assertSame('2.2.2.2', $instance->meta['ip']);
        $this->assertSame(2000, $instance->meta['port']);
        $this->assertSame('new', $instance->meta['username']);
        $this->assertSame('new', $instance->meta['password']);
        $this->assertSame(SubscriptionInstance::STATUS_ACTIVE, $instance->status);
    }

    public function test_expiry_callback_marks_the_instance_disabled(): void
    {
        $instance = $this->instanceWithProxyId('SI2');

        $this->hitCallback([
            'type' => 'UPDATE_STATIC_INSTANCE',
            'proxyId' => 'SI2',
            'changeType' => 'EXPIRY',
            'data' => ['proxyId' => 'SI2', 'status' => 3],
        ])->assertOk();

        $this->assertSame(SubscriptionInstance::STATUS_DISABLED, $instance->fresh()->status);
    }

    public function test_callback_updates_an_instance_inside_the_matching_tenant(): void
    {
        $tenant = Tenant::create(['id' => 'onip-shop']);
        $tenant->run(fn () => $this->instanceWithProxyId('TENANT-SI'));

        $this->hitCallback([
            'type' => 'UPDATE_STATIC_INSTANCE',
            'data' => [
                'proxyId' => 'TENANT-SI',
                'ip' => '3.3.3.3',
                'port' => 3000,
                'username' => 'tenant-new',
                'password' => 'tenant-pass',
                'status' => 2,
            ],
        ])->assertOk()->assertJson(['status' => 'success']);

        $tenant->run(function () {
            $instance = SubscriptionInstance::whereJsonContains('meta->proxy_id', 'TENANT-SI')->firstOrFail();
            $this->assertSame('3.3.3.3', $instance->meta['ip']);
            $this->assertSame(3000, $instance->meta['port']);
            $this->assertSame('tenant-new', $instance->meta['username']);
            $this->assertSame('tenant-pass', $instance->meta['password']);
        });
    }

    public function test_callback_refuses_a_proxy_id_that_exists_in_more_than_one_site(): void
    {
        $central = $this->instanceWithProxyId('DUPLICATE-SI');
        $tenant = Tenant::create(['id' => 'duplicate-shop']);
        $tenant->run(fn () => $this->instanceWithProxyId('DUPLICATE-SI'));

        $this->hitCallback([
            'type' => 'UPDATE_STATIC_INSTANCE',
            'data' => ['proxyId' => 'DUPLICATE-SI', 'ip' => '8.8.8.8'],
        ])->assertStatus(409)->assertJson(['status' => 'ambiguous']);

        $this->assertSame('1.1.1.1', $central->fresh()->meta['ip']);
        $tenant->run(function () {
            $instance = SubscriptionInstance::whereJsonContains('meta->proxy_id', 'DUPLICATE-SI')->firstOrFail();
            $this->assertSame('1.1.1.1', $instance->meta['ip']);
        });
    }

    public function test_callback_for_unknown_proxy_id_is_ignored_without_error(): void
    {
        $this->hitCallback([
            'type' => 'UPDATE_STATIC_INSTANCE',
            'proxyId' => 'does-not-exist',
            'changeType' => 'IP_CHANGE',
            'data' => ['proxyId' => 'does-not-exist', 'ip' => '9.9.9.9'],
        ])->assertOk()->assertJson(['status' => 'ignored']);
    }

    public function test_create_static_order_callback_type_is_ignored(): void
    {
        $this->hitCallback([
            'type' => 'CREATE_STATIC_ORDER',
            'data' => ['orderNo' => 'ORD1'],
        ])->assertOk()->assertJson(['status' => 'ignored']);
    }

    public function test_callback_without_secret_is_rejected_and_mutates_nothing(): void
    {
        $instance = $this->instanceWithProxyId('SI3');

        // No ?secret= at all — a spoofed request that knows the proxy_id.
        $this->postJson('/api/callbacks/onip', [
            'type' => 'UPDATE_STATIC_INSTANCE',
            'proxyId' => 'SI3',
            'data' => ['proxyId' => 'SI3', 'ip' => '6.6.6.6', 'expiresAt' => now()->addYears(5)->valueOf()],
        ])->assertStatus(403);

        // The instance's IP and expiry are untouched.
        $this->assertSame('1.1.1.1', $instance->fresh()->meta['ip']);
        $this->assertTrue($instance->fresh()->expired_at->lt(now()->addMonths(2)));
    }

    public function test_callback_with_wrong_secret_is_rejected(): void
    {
        $instance = $this->instanceWithProxyId('SI4');

        $this->postJson('/api/callbacks/onip?secret=WRONG', [
            'type' => 'UPDATE_STATIC_INSTANCE',
            'proxyId' => 'SI4',
            'data' => ['proxyId' => 'SI4', 'ip' => '6.6.6.6'],
        ])->assertStatus(403);

        $this->assertSame('1.1.1.1', $instance->fresh()->meta['ip']);
    }

    public function test_callback_is_closed_when_no_secret_is_configured(): void
    {
        Setting::set('onip_callback_secret', ''); // fail-closed default
        $instance = $this->instanceWithProxyId('SI5');

        $this->postJson('/api/callbacks/onip?secret=', [
            'type' => 'UPDATE_STATIC_INSTANCE',
            'proxyId' => 'SI5',
            'data' => ['proxyId' => 'SI5', 'ip' => '6.6.6.6'],
        ])->assertStatus(403);

        $this->assertSame('1.1.1.1', $instance->fresh()->meta['ip']);
    }
}
