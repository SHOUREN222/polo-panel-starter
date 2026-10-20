<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SwitchOrderDoubleCreditTest extends TestCase
{
    use RefreshDatabase;

    public function test_two_pending_switch_orders_cannot_double_credit_the_same_traffic(): void
    {
        Setting::set('plan_change_proration_enabled', '1');

        $plan100 = Plan::create([
            'name' => '100G', 'type' => Plan::TYPE_SHARED, 'group_id' => 1,
            'transfer_enable' => 100 * 1024 * 1024 * 1024, 'prices' => ['month' => 6500], 'sell' => true,
        ]);
        $plan20 = Plan::create([
            'name' => '20G', 'type' => Plan::TYPE_SHARED, 'group_id' => 1,
            'transfer_enable' => 20 * 1024 * 1024 * 1024, 'prices' => ['month' => 1500], 'sell' => true,
        ]);

        $svc = app(OrderService::class);
        $user = User::factory()->create();
        $svc->markPaid($svc->createOrder($user, $plan100, 'month')); // on 100G, paid ¥65

        // Two switch orders created before paying either — the exploit.
        $orderA = $svc->createChangeOrder($user->fresh(), $plan20, 'month');
        $orderB = $svc->createChangeOrder($user->fresh(), $plan20, 'month');

        // The earlier one is cancelled, so its stale credit can't be realized.
        $this->assertSame(Order::STATUS_CANCELLED, $orderA->fresh()->status);
        $this->assertSame(Order::STATUS_PENDING, $orderB->fresh()->status);

        $svc->markPaid($orderB->fresh());

        // Credited exactly once (¥50 = 100G value ¥65 − 20G price ¥15), not ¥100.
        $this->assertSame(5000, $user->fresh()->balance);
    }
}
