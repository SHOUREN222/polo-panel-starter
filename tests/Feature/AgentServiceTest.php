<?php

namespace Tests\Feature;

use App\Models\GiftCard;
use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use App\Services\AgentService;
use App\Services\GiftCardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AgentServiceTest extends TestCase
{
    use RefreshDatabase;

    private function agent(int $discount = 20, int $balance = 100000): User
    {
        return User::factory()->create([
            'is_agent' => true,
            'agent_discount' => $discount,
            'balance' => $balance, // ¥1000
        ]);
    }

    public function test_generating_cards_charges_the_discounted_cost_and_stamps_ownership(): void
    {
        $agent = $this->agent(20, 100000); // 20% off, ¥1000 balance

        $cards = app(AgentService::class)->generateCards($agent, 10000, 3); // 3 × ¥100 face

        $this->assertCount(3, $cards);
        // Each ¥100 card costs the 20%-off agent ¥80 → 3 × 8000 = 24000 charged.
        $this->assertSame(100000 - 24000, $agent->fresh()->balance);
        foreach ($cards as $card) {
            $this->assertSame(10000, $card->amount);
            $this->assertSame(8000, $card->agent_cost);
            $this->assertSame($agent->id, $card->created_by_agent_id);
            $this->assertFalse($card->redeemed);
        }
    }

    public function test_insufficient_balance_is_rejected_and_charges_nothing(): void
    {
        $agent = $this->agent(0, 5000); // ¥50 balance, no discount

        try {
            app(AgentService::class)->generateCards($agent, 10000, 1); // needs ¥100
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('余额不足', collect($e->errors())->flatten()->first());
        }

        $this->assertSame(5000, $agent->fresh()->balance);
        $this->assertSame(0, GiftCard::count());
    }

    public function test_a_non_agent_cannot_generate(): void
    {
        $user = User::factory()->create(['is_agent' => false, 'balance' => 100000]);

        $this->expectException(ValidationException::class);
        app(AgentService::class)->generateCards($user, 10000, 1);
    }

    public function test_quantity_and_face_bounds_are_enforced(): void
    {
        $agent = $this->agent();

        try {
            app(AgentService::class)->generateCards($agent, 50, 1); // face < ¥1
            $this->fail('expected face-too-small rejection');
        } catch (ValidationException $e) {
            $this->assertNotEmpty($e->errors());
        }

        $this->expectException(ValidationException::class);
        app(AgentService::class)->generateCards($agent, 10000, 101); // over MAX_QUANTITY
    }

    public function test_an_agent_generated_card_redeems_like_any_gift_card(): void
    {
        $agent = $this->agent(30, 100000);
        $card = app(AgentService::class)->generateCards($agent, 5000, 1)->first();

        $customer = User::factory()->create(['balance' => 0]);
        app(GiftCardService::class)->redeem($customer, $card->code);

        // Customer gets the full face value; card is marked sold.
        $this->assertSame(5000, $customer->fresh()->balance);
        $this->assertTrue($card->fresh()->redeemed);
        $this->assertSame($customer->id, $card->fresh()->redeemed_by_user_id);
    }

    private function sharedPlan(): Plan
    {
        return Plan::create([
            'name' => '共享套餐', 'type' => Plan::TYPE_SHARED, 'group_id' => 1,
            'transfer_enable' => 20 * 1024 * 1024 * 1024, 'prices' => ['month' => 10000],
            'sell' => true, 'show' => true,
        ]);
    }

    public function test_open_for_customer_charges_agent_and_provisions_the_customer(): void
    {
        $agent = $this->agent(20, 100000); // 20% off
        $plan = $this->sharedPlan();       // ¥100/month retail
        $customer = User::factory()->create(['balance' => 0]);

        $order = app(AgentService::class)->openForCustomer($agent, $customer, $plan, 'month');

        // Agent paid the discounted ¥80; customer paid nothing.
        $this->assertSame(100000 - 8000, $agent->fresh()->balance);
        $this->assertSame(0, $customer->fresh()->balance);
        // Order is fulfilled on the customer, stamped with the agent, retail total.
        $this->assertTrue($order->isPaid());
        $this->assertSame($customer->id, $order->user_id);
        $this->assertSame($agent->id, $order->paid_by_agent_id);
        $this->assertSame(10000, $order->total_amount);
        // Customer actually got the plan.
        $this->assertTrue($customer->fresh()->hasActiveSharedPlan());
    }

    public function test_open_for_customer_rejected_when_agent_balance_too_low(): void
    {
        $agent = $this->agent(0, 5000); // ¥50, no discount
        $plan = $this->sharedPlan();    // needs ¥100
        $customer = User::factory()->create();

        try {
            app(AgentService::class)->openForCustomer($agent, $customer, $plan, 'month');
            $this->fail('expected insufficient-balance rejection');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('余额不足', collect($e->errors())->flatten()->first());
        }

        $this->assertSame(5000, $agent->fresh()->balance);
        $this->assertSame(0, Order::where('paid_by_agent_id', $agent->id)->count());
    }

    public function test_dynamic_isp_open_requires_a_picked_line(): void
    {
        // 任选地区 (dynamic) plans are now openable via 代客开通, but only with a
        // picked region — without one, createOrder rejects it.
        $agent = $this->agent();
        $plan = Plan::create([
            'name' => '任选地区', 'type' => Plan::TYPE_DEDICATED_ISP,
            'transfer_enable' => 50 * 1024 * 1024 * 1024, 'prices' => ['month' => 5000],
            'resource_profile' => ['provider' => 'onip_dynamic'], 'sell' => true, 'show' => true,
        ]);
        $customer = User::factory()->create();

        $this->expectException(ValidationException::class);
        app(AgentService::class)->openForCustomer($agent, $customer, $plan, 'month', null);
    }
}
