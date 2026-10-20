<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CheckoutPaymentTest extends TestCase
{
    use RefreshDatabase;

    private function makeSharedPlan(): Plan
    {
        return Plan::create([
            'name' => '20GB 基础共享线路', 'type' => Plan::TYPE_SHARED, 'group_id' => 1,
            'transfer_enable' => 20 * 1024 * 1024 * 1024, 'prices' => ['month' => 1500],
            'sell' => true,
        ]);
    }

    public function test_purchase_page_renders_the_plan_management_description_verbatim(): void
    {
        $description = "后台套餐说明第一行\n• 后台套餐说明第二行";
        $plan = Plan::create([
            'name' => '后台说明同步测试', 'type' => Plan::TYPE_SHARED, 'group_id' => 1,
            'transfer_enable' => 20 * 1024 * 1024 * 1024, 'prices' => ['month' => 1500],
            'description' => $description, 'sell' => true,
        ]);
        $user = User::factory()->create();

        Livewire::actingAs($user)->test('plans')
            ->assertSeeText('后台套餐说明第一行')
            ->assertSeeText('后台套餐说明第二行');
    }

    public function test_buying_opens_an_in_place_checkout_with_real_epay_buttons_when_configured(): void
    {
        Setting::set('epay_gateway_url', 'https://epay.example.com');
        Setting::set('epay_pid', '1175');
        Setting::set('epay_key', 'test-key');
        Payment::create(['name' => '支付宝', 'gateway' => 'epay_alipay', 'enable' => true]);
        Payment::create(['name' => '微信支付', 'gateway' => 'epay_wxpay', 'enable' => true]);

        $plan = $this->makeSharedPlan();
        $user = User::factory()->create();

        Livewire::actingAs($user)->test('plans')
            ->call('buy', $plan->id, 'month')
            ->assertSet('justCreatedOrderId', fn ($id) => is_int($id))
            ->assertSee('订单付款')
            ->assertSee('支付宝')
            ->assertSee('微信支付')
            ->assertDontSee('模拟支付');

        $order = Order::where('plan_id', $plan->id)->firstOrFail();
        $this->assertSame(Order::STATUS_PENDING, $order->status);
    }

    public function test_buying_falls_back_to_mock_pay_button_when_no_gateway_configured(): void
    {
        $plan = $this->makeSharedPlan();
        $user = User::factory()->create();

        $component = Livewire::actingAs($user)->test('plans')
            ->call('buy', $plan->id, 'month')
            ->assertSee('模拟支付');

        $order = Order::where('plan_id', $plan->id)->firstOrFail();

        $component->call('payMock', $order->id);

        $this->assertSame(Order::STATUS_PAID, $order->fresh()->status);
    }

    public function test_a_customer_cannot_mock_pay_someone_elses_order(): void
    {
        $plan = $this->makeSharedPlan();
        $owner = User::factory()->create();
        $attacker = User::factory()->create();

        Livewire::actingAs($owner)->test('plans')->call('buy', $plan->id, 'month');
        $order = Order::where('plan_id', $plan->id)->firstOrFail();

        $this->expectException(ModelNotFoundException::class);
        Livewire::actingAs($attacker)->test('plans')->call('payMock', $order->id);
    }
}
