<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MultiPeriodSwitchCreditTest extends TestCase
{
    use RefreshDatabase;

    public function test_yearly_buyer_switching_to_monthly_is_credited_for_unused_paid_months(): void
    {
        Setting::set('plan_change_proration_enabled', '1');

        $plan20 = Plan::create([
            'name' => '20G', 'type' => Plan::TYPE_SHARED, 'group_id' => 1,
            'transfer_enable' => 20 * 1024 * 1024 * 1024,
            'prices' => ['month' => 1500, 'year' => 17500], 'sell' => true,
        ]);
        $plan100 = Plan::create([
            'name' => '100G', 'type' => Plan::TYPE_SHARED, 'group_id' => 1,
            'transfer_enable' => 100 * 1024 * 1024 * 1024,
            'prices' => ['month' => 6500], 'sell' => true,
        ]);

        $svc = app(OrderService::class);
        $user = User::factory()->create();
        $svc->markPaid($svc->createOrder($user, $plan20, 'year')); // paid ¥175, unused

        $order = $svc->createChangeOrder($user->fresh(), $plan100, 'month');

        // Fair: ¥65 month covered by credit (order ¥0), the rest of the paid
        // year (~¥110) returned to balance — NOT forfeited down to one month.
        $this->assertSame(0, $order->total_amount);
        $this->assertSame(6500, $order->discount_amount);
        $this->assertSame(11000, $order->balance_credit_amount);
    }

    public function test_monthly_switch_credit_is_unchanged(): void
    {
        Setting::set('plan_change_proration_enabled', '1');

        $plan20 = Plan::create([
            'name' => '20G', 'type' => Plan::TYPE_SHARED, 'group_id' => 1,
            'transfer_enable' => 20 * 1024 * 1024 * 1024,
            'prices' => ['month' => 1500], 'sell' => true,
        ]);
        $plan100 = Plan::create([
            'name' => '100G', 'type' => Plan::TYPE_SHARED, 'group_id' => 1,
            'transfer_enable' => 100 * 1024 * 1024 * 1024,
            'prices' => ['month' => 6500], 'sell' => true,
        ]);

        $svc = app(OrderService::class);
        $user = User::factory()->create();
        $svc->markPaid($svc->createOrder($user, $plan20, 'month')); // paid ¥15

        $order = $svc->createChangeOrder($user->fresh(), $plan100, 'month');

        // Monthly: only the current month's ¥15 traffic value, no future months.
        $this->assertSame(1500, $order->discount_amount);
        $this->assertSame(0, $order->balance_credit_amount);
        $this->assertSame(5000, $order->total_amount); // ¥65 − ¥15
    }
}
