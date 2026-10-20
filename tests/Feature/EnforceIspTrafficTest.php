<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Plan;
use App\Models\SubscriptionInstance;
use App\Models\TrafficPack;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class EnforceIspTrafficTest extends TestCase
{
    use RefreshDatabase;

    private function onip(array $attrs = []): SubscriptionInstance
    {
        $user = User::factory()->create();
        $plan = Plan::create([
            'name' => 'ISP', 'type' => Plan::TYPE_DEDICATED_ISP,
            'transfer_enable' => 1000, 'prices' => ['month' => 1000],
            'resource_profile' => ['provider' => 'stub'], 'sell' => true,
        ]);

        return SubscriptionInstance::create(array_merge([
            'user_id' => $user->id, 'plan_id' => $plan->id,
            'uuid' => (string) Str::uuid(),
            'transfer_enable' => 1000, 'u' => 0, 'd' => 0,
            'status' => SubscriptionInstance::STATUS_ACTIVE,
            'expired_at' => now()->addDays(30),
            'meta' => ['provider' => 'onip', 'ip' => '1.2.3.4', 'port' => 1080, 'username' => 'u', 'password' => 'p'],
        ], $attrs));
    }

    public function test_over_quota_active_line_is_suspended(): void
    {
        $i = $this->onip(['transfer_enable' => 1000, 'u' => 600, 'd' => 500]); // 1100 >= 1000

        $this->artisan('isp:enforce-traffic')->assertExitCode(0);

        $this->assertSame(SubscriptionInstance::STATUS_SUSPENDED, $i->fresh()->status);
    }

    public function test_under_quota_active_line_stays_active(): void
    {
        $i = $this->onip(['transfer_enable' => 1000, 'u' => 300, 'd' => 200]); // 500 < 1000

        $this->artisan('isp:enforce-traffic')->assertExitCode(0);

        $this->assertSame(SubscriptionInstance::STATUS_ACTIVE, $i->fresh()->status);
    }

    public function test_suspended_line_is_revived_when_back_under_quota(): void
    {
        $i = $this->onip([
            'status' => SubscriptionInstance::STATUS_SUSPENDED,
            'transfer_enable' => 5000, 'u' => 600, 'd' => 500, // 1100 < 5000
        ]);

        $this->artisan('isp:enforce-traffic')->assertExitCode(0);

        $this->assertSame(SubscriptionInstance::STATUS_ACTIVE, $i->fresh()->status);
    }

    public function test_buying_a_pack_revives_a_suspended_line(): void
    {
        $i = $this->onip([
            'status' => SubscriptionInstance::STATUS_SUSPENDED,
            'transfer_enable' => 1000, 'u' => 600, 'd' => 500, // 1100 >= 1000 (still over base)
        ]);
        // Pack lifts the effective quota above usage.
        $order = Order::create([
            'user_id' => $i->user_id, 'plan_id' => $i->plan_id,
            'type' => Order::TYPE_TRAFFIC_PACK, 'period' => 'once',
            'trade_no' => uniqid('t'), 'total_amount' => 0, 'status' => Order::STATUS_PAID,
        ]);
        TrafficPack::create([
            'user_id' => $i->user_id, 'plan_id' => $i->plan_id,
            'subscription_instance_id' => $i->id, 'order_id' => $order->id,
            'kind' => TrafficPack::KIND_ONE_TIME, 'bytes' => 2000,
        ]);

        $this->artisan('isp:enforce-traffic')->assertExitCode(0);

        $this->assertSame(SubscriptionInstance::STATUS_ACTIVE, $i->fresh()->status);
    }

    public function test_disabled_and_non_onip_lines_are_untouched(): void
    {
        $disabled = $this->onip(['status' => SubscriptionInstance::STATUS_DISABLED, 'transfer_enable' => 1000, 'u' => 5000]);
        $stub = $this->onip(['meta' => ['provider' => 'stub'], 'transfer_enable' => 1000, 'u' => 5000]);

        $this->artisan('isp:enforce-traffic')->assertExitCode(0);

        $this->assertSame(SubscriptionInstance::STATUS_DISABLED, $disabled->fresh()->status);
        $this->assertSame(SubscriptionInstance::STATUS_ACTIVE, $stub->fresh()->status);
    }
}
