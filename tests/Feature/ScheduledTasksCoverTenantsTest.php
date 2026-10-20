<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\SubscriptionInstance;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\SubscriptionExpiringSoon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The scheduler runs in central context, and subscriptions live in each site's
 * own database — so anything it drives skipped 分站 customers entirely. Expiry
 * reminders never reached them, and, more expensively, over-quota ISP lines
 * were never cut off while the platform kept paying for the bandwidth.
 */
class ScheduledTasksCoverTenantsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        if (tenant()) {
            tenancy()->end();
        }
        foreach (Tenant::all() as $t) {
            $t->delete();
        }
        parent::tearDown();
    }

    private function ispPlan(): Plan
    {
        return Plan::create([
            'name' => 'ISP', 'type' => Plan::TYPE_DEDICATED_ISP,
            'transfer_enable' => 1024, 'prices' => ['month' => 5000],
            'resource_profile' => ['provider' => 'onip'], 'sell' => true,
        ]);
    }

    public function test_expiry_reminders_reach_tenant_customers(): void
    {
        Notification::fake();
        $plan = $this->ispPlan();

        $t = Tenant::create(['id' => 'remind']);
        $tenantUser = null;
        $t->run(function () use ($plan, &$tenantUser) {
            $tenantUser = User::create(['name' => '分站客户', 'email' => 'c@example.com', 'password' => 'x']);
            SubscriptionInstance::create([
                'user_id' => $tenantUser->id, 'plan_id' => $plan->id,
                'status' => SubscriptionInstance::STATUS_ACTIVE,
                'transfer_enable' => 1024,
                'expired_at' => now()->addDays(2),
                'meta' => ['provider' => 'onip'],
            ]);
        });

        $this->artisan('app:send-usage-reminders')->assertSuccessful();

        $t->run(function () use ($tenantUser) {
            Notification::assertSentTo(
                User::find($tenantUser->id),
                SubscriptionExpiringSoon::class
            );
        });
    }

    public function test_over_quota_tenant_lines_are_suspended(): void
    {
        $plan = $this->ispPlan();

        $t = Tenant::create(['id' => 'quota']);
        $instanceId = $t->run(function () use ($plan) {
            $u = User::create(['name' => 'c', 'email' => 'c@example.com', 'password' => 'x']);

            return SubscriptionInstance::create([
                'user_id' => $u->id, 'plan_id' => $plan->id,
                'status' => SubscriptionInstance::STATUS_ACTIVE,
                'transfer_enable' => 1000,
                'u' => 900, 'd' => 500,               // 1400 used against a 1000 quota
                'expired_at' => now()->addMonth(),
                'meta' => ['provider' => 'onip'],
            ])->id;
        });

        $this->artisan('isp:enforce-traffic')->assertSuccessful();

        $status = $t->run(fn () => SubscriptionInstance::find($instanceId)->status);
        $this->assertSame(SubscriptionInstance::STATUS_SUSPENDED, $status, '超额的分站线路应被断流');
    }

    public function test_a_tenant_line_under_quota_is_left_running(): void
    {
        $plan = $this->ispPlan();

        $t = Tenant::create(['id' => 'under']);
        $instanceId = $t->run(function () use ($plan) {
            $u = User::create(['name' => 'c', 'email' => 'c@example.com', 'password' => 'x']);

            return SubscriptionInstance::create([
                'user_id' => $u->id, 'plan_id' => $plan->id,
                'status' => SubscriptionInstance::STATUS_ACTIVE,
                'transfer_enable' => 10000,
                'u' => 100, 'd' => 100,
                'expired_at' => now()->addMonth(),
                'meta' => ['provider' => 'onip'],
            ])->id;
        });

        $this->artisan('isp:enforce-traffic')->assertSuccessful();

        $this->assertSame(
            SubscriptionInstance::STATUS_ACTIVE,
            $t->run(fn () => SubscriptionInstance::find($instanceId)->status)
        );
    }

    public function test_the_main_site_is_still_enforced(): void
    {
        $plan = $this->ispPlan();
        $u = User::factory()->create();
        $instance = SubscriptionInstance::create([
            'user_id' => $u->id, 'plan_id' => $plan->id,
            'status' => SubscriptionInstance::STATUS_ACTIVE,
            'transfer_enable' => 1000, 'u' => 900, 'd' => 500,
            'expired_at' => now()->addMonth(),
            'meta' => ['provider' => 'onip'],
        ]);

        $this->artisan('isp:enforce-traffic')->assertSuccessful();

        $this->assertSame(SubscriptionInstance::STATUS_SUSPENDED, $instance->fresh()->status);
    }
}
