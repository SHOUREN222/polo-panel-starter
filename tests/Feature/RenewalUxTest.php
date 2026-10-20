<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Server;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RenewalUxTest extends TestCase
{
    use RefreshDatabase;

    public function test_plans_page_labels_the_buy_button_as_renew_for_the_users_current_shared_plan(): void
    {
        $user = User::factory()->create();
        $plan = Plan::create([
            'name' => '共享套餐', 'type' => Plan::TYPE_SHARED, 'group_id' => 1,
            'transfer_enable' => 1, 'prices' => ['month' => 1000], 'sell' => true,
        ]);
        Server::create(['name' => '节点A', 'group_id' => 1, 'host' => '198.51.100.5', 'port' => 8443]);
        app(OrderService::class)->markPaid(app(OrderService::class)->createOrder($user, $plan, 'month'));

        $response = $this->actingAs($user->refresh())->get('/plans');

        $response->assertOk();
        // The sidebar nav always has a "续费订阅" link, so match the button's
        // own text ("月付 续费 ¥...") rather than the bare "续费" substring.
        $response->assertSeeText('续费 ¥10.00');
    }

    public function test_plans_page_does_not_show_renew_label_for_a_plan_the_user_does_not_own(): void
    {
        $user = User::factory()->create();
        Plan::create([
            'name' => '共享套餐', 'type' => Plan::TYPE_SHARED, 'group_id' => 1,
            'transfer_enable' => 1, 'prices' => ['month' => 1000], 'sell' => true,
        ]);

        $response = $this->actingAs($user)->get('/plans');

        $response->assertOk();
        $response->assertDontSeeText('续费 ¥');
    }

    public function test_renew_page_points_shared_plan_owners_to_the_plans_page(): void
    {
        $user = User::factory()->create();
        $plan = Plan::create([
            'name' => '共享套餐', 'type' => Plan::TYPE_SHARED, 'group_id' => 1,
            'transfer_enable' => 1, 'prices' => ['month' => 1000], 'sell' => true,
        ]);
        Server::create(['name' => '节点A', 'group_id' => 1, 'host' => '198.51.100.5', 'port' => 8443]);
        app(OrderService::class)->markPaid(app(OrderService::class)->createOrder($user, $plan, 'month'));

        $response = $this->actingAs($user->refresh())->get('/renew');

        $response->assertOk();
        $response->assertSeeText('机房共享套餐的续费仍在');
    }

    public function test_renew_page_does_not_mention_shared_plan_note_for_a_user_without_one(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/renew');

        $response->assertOk();
        $response->assertDontSeeText('机房共享套餐的续费仍在');
        $response->assertSeeText('还没有可续费的专属线路');
    }
}
