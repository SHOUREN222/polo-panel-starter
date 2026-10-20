<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Services\OrderService;
use App\Services\WholesaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * An agent's dashboard used to show only retail takings, which overstates what
 * they keep by several times, and gave no sight of 进货余额 at all — the number
 * that decides whether their shop can serve anyone. The first sign it had run
 * out was a customer's order failing.
 */
class AgentDashboardEconomicsTest extends TestCase
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

    private function sharedPlan(): Plan
    {
        return Plan::create([
            'name' => '共享', 'type' => Plan::TYPE_SHARED, 'group_id' => 1,
            'transfer_enable' => 100 * 1024 * 1024 * 1024,
            'prices' => ['month' => 1500], 'sell' => true,
        ]);
    }

    public function test_agent_sees_credit_balance_and_what_they_actually_keep(): void
    {
        Notification::fake();
        $plan = $this->sharedPlan();

        $t = Tenant::create(['id' => 'econ', 'agent_discount' => 30]);
        app(WholesaleService::class)->credit('econ', 20000); // ¥200

        tenancy()->initialize($t);
        $agent = User::create(['name' => '代理', 'email' => 'a@example.com', 'password' => 'x']);
        $agent->forceFill(['is_admin' => true, 'admin_role' => User::ROLE_SUPER])->save();
        $customer = User::create(['name' => 'c', 'email' => 'c@example.com', 'password' => 'x']);
        app(OrderService::class)->markPaid(app(OrderService::class)->createOrder($customer, $plan, 'month'));

        // Assert on what's rendered — these come from with(), not component
        // state, so Livewire's ->get() would read null and pass vacuously.
        Livewire::actingAs($agent)->test('admin.dashboard')
            ->assertSee('进货余额')
            ->assertSee('¥185.00')   // ¥200 of balance − the ¥15 sale, 1:1
            ->assertSee('本月净利')
            ->assertSee('¥4.50')     // ¥15 sold; the balance it burned cost ¥10.50
            ->assertSee('¥10.50')    // what those ¥15 of balance cost in cash
            ->assertSee('30% off');

        $this->assertSame(20000 - 1500, app(WholesaleService::class)->balance('econ'));
        tenancy()->end();
    }

    public function test_an_empty_balance_is_called_out_on_the_agents_dashboard(): void
    {
        $t = Tenant::create(['id' => 'broke', 'agent_discount' => 30]);

        tenancy()->initialize($t);
        $agent = User::create(['name' => '代理', 'email' => 'a@example.com', 'password' => 'x']);
        $agent->forceFill(['is_admin' => true, 'admin_role' => User::ROLE_SUPER])->save();

        Livewire::actingAs($agent)->test('admin.dashboard')
            ->assertSee('进货余额')
            ->assertSee('客户无法开通');

        tenancy()->end();
    }

    public function test_the_main_site_dashboard_is_unchanged(): void
    {
        // 主站 has no wholesale — those tiles must not appear at all.
        $admin = User::factory()->create(['is_admin' => true, 'admin_role' => User::ROLE_SUPER]);

        Livewire::actingAs($admin)->test('admin.dashboard')
            ->assertDontSee('进货余额')
            ->assertDontSee('你的进货折扣')
            ->assertDontSee('本月净利');
    }

    public function test_agent_dashboard_hides_platform_infrastructure(): void
    {
        // Node count and queue health belong to the platform: an agent can't
        // act on either, and it needlessly tells them how many nodes we run
        // and whether our queue is backed up.
        $t = Tenant::create(['id' => 'infra', 'agent_discount' => 20]);

        tenancy()->initialize($t);
        $agent = User::create(['name' => '代理', 'email' => 'a@example.com', 'password' => 'x']);
        $agent->forceFill(['is_admin' => true, 'admin_role' => User::ROLE_SUPER])->save();

        Livewire::actingAs($agent)->test('admin.dashboard')
            ->assertDontSee('系统状态')
            ->assertDontSee('节点在线')
            ->assertDontSee('待处理任务数')
            // …while the agent's own business figures stay.
            ->assertSee('进货余额')
            ->assertSee('总用户');

        tenancy()->end();
    }

    public function test_the_platform_owner_still_sees_infrastructure(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'admin_role' => User::ROLE_SUPER]);

        Livewire::actingAs($admin)->test('admin.dashboard')
            ->assertSee('系统状态')
            ->assertSee('节点在线');
    }
}
