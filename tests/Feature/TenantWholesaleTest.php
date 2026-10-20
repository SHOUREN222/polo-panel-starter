<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\TenantWholesaleSettlement;
use App\Models\User;
use App\Services\OrderService;
use App\Services\WholesaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class TenantWholesaleTest extends TestCase
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
            'transfer_enable' => 20 * 1024 * 1024 * 1024, 'prices' => ['month' => 1500], 'sell' => true,
        ]);
    }

    public function test_credit_logs_a_topup_record_alongside_the_balance(): void
    {
        Tenant::create(['id' => 'topupshop']);

        $balance = app(WholesaleService::class)->credit('topupshop', 10000, 7000);

        $this->assertSame(10000, $balance);
        $this->assertDatabaseHas('tenant_wholesale_topups', [
            'tenant_id' => 'topupshop', 'amount' => 10000, 'cash_collected' => 7000,
        ]);
    }

    public function test_delivering_a_tenant_order_debits_the_wholesale_balance(): void
    {
        Notification::fake();
        $plan = $this->sharedPlan();
        $t = Tenant::create(['id' => 'wshop', 'agent_discount' => 20, 'node_ordinal' => 1]);
        app(WholesaleService::class)->credit('wshop', 10000); // ¥100 pre-funded

        tenancy()->initialize($t);
        $user = User::create(['name' => 'c', 'email' => 'c@example.com', 'password' => 'x']);
        $order = app(OrderService::class)->createOrder($user, $plan, 'month');
        app(OrderService::class)->markPaid($order);
        app(OrderService::class)->markPaid($order->fresh());
        $this->assertTrue($order->fresh()->isPaid());
        tenancy()->end();

        // The discount was taken at top-up, so a ¥15 sale consumes ¥15 of
        // balance, not ¥12. Discounting again here would hand the agent a
        // second 20% the platform never charged for.
        $this->assertSame(10000 - 1500, app(WholesaleService::class)->balance('wshop'));
        $this->assertDatabaseHas('tenant_wholesale_settlements', [
            'tenant_id' => 'wshop',
            'order_id' => $order->id,
            'amount' => 1500,
            'status' => TenantWholesaleSettlement::STATUS_COMPLETED,
        ]);
    }

    public function test_delivery_is_blocked_when_wholesale_is_insufficient(): void
    {
        Notification::fake();
        $plan = $this->sharedPlan();
        $t = Tenant::create(['id' => 'wshop2', 'agent_discount' => 0, 'node_ordinal' => 1]); // wholesale = full ¥15
        app(WholesaleService::class)->credit('wshop2', 500); // only ¥5

        tenancy()->initialize($t);
        $user = User::create(['name' => 'c', 'email' => 'c@example.com', 'password' => 'x']);
        $order = app(OrderService::class)->createOrder($user, $plan, 'month');

        try {
            app(OrderService::class)->markPaid($order);
            $this->fail('expected wholesale block');
        } catch (ValidationException $e) {
            // Shown to the agent's own customer, so it must not reveal that the
            // shop resells a platform — the agent is alerted separately.
            $message = collect($e->errors())->flatten()->first();
            $this->assertStringNotContainsString('进货余额', $message);
            $this->assertStringNotContainsString('站长', $message);
            $this->assertStringContainsString('联系客服', $message);
        }

        $this->assertFalse($order->fresh()->isPaid());          // NOT delivered
        $this->assertFalse($user->fresh()->hasActiveSharedPlan());
        tenancy()->end();

        $this->assertSame(500, app(WholesaleService::class)->balance('wshop2')); // untouched
        $this->assertDatabaseMissing('tenant_wholesale_settlements', [
            'tenant_id' => 'wshop2',
            'order_id' => $order->id,
        ]);
    }

    public function test_reserving_the_same_order_twice_debits_only_once(): void
    {
        $plan = $this->sharedPlan();
        $t = Tenant::create(['id' => 'reserve-once', 'node_ordinal' => 1]);
        app(WholesaleService::class)->credit('reserve-once', 5000);

        tenancy()->initialize($t);
        $user = User::create(['name' => 'c', 'email' => 'reserve@example.com', 'password' => 'x']);
        $order = app(OrderService::class)->createOrder($user, $plan, 'month');
        $first = app(WholesaleService::class)->reserve($order);
        $second = app(WholesaleService::class)->reserve($order);
        tenancy()->end();

        $this->assertSame($first->id, $second->id);
        $this->assertSame(3500, app(WholesaleService::class)->balance('reserve-once'));
        $this->assertSame(1, TenantWholesaleSettlement::where('tenant_id', 'reserve-once')->count());
    }

    public function test_two_orders_cannot_take_the_wholesale_balance_negative(): void
    {
        Notification::fake();
        $plan = $this->sharedPlan();
        $t = Tenant::create(['id' => 'no-negative', 'node_ordinal' => 1]);
        app(WholesaleService::class)->credit('no-negative', 2000);

        tenancy()->initialize($t);
        $user = User::create(['name' => 'c', 'email' => 'negative@example.com', 'password' => 'x']);
        $first = app(OrderService::class)->createOrder($user, $plan, 'month');
        $second = app(OrderService::class)->createOrder($user, $plan, 'month');
        app(WholesaleService::class)->reserve($first);

        try {
            app(WholesaleService::class)->reserve($second);
            $this->fail('expected wholesale block');
        } catch (ValidationException) {
        }
        tenancy()->end();

        $this->assertSame(500, app(WholesaleService::class)->balance('no-negative'));
    }

    public function test_failed_fulfillment_releases_the_reservation_for_retry(): void
    {
        Notification::fake();
        $plan = $this->sharedPlan();
        $t = Tenant::create(['id' => 'release-failed', 'node_ordinal' => 1]);
        app(WholesaleService::class)->credit('release-failed', 5000);

        tenancy()->initialize($t);
        $user = User::create(['name' => 'c', 'email' => 'failed@example.com', 'password' => 'x']);
        $order = Order::create([
            'user_id' => $user->id,
            'plan_id' => $plan->id + 999,
            'type' => Order::TYPE_NEW,
            'period' => 'month',
            'trade_no' => 'FAIL-AFTER-RESERVE',
            'total_amount' => 1500,
            'status' => Order::STATUS_PENDING,
        ]);

        try {
            app(OrderService::class)->markPaid($order);
            $this->fail('expected fulfillment failure');
        } catch (\Error) {
        }
        $this->assertFalse($order->fresh()->isPaid());
        tenancy()->end();

        $this->assertSame(5000, app(WholesaleService::class)->balance('release-failed'));
        $this->assertDatabaseHas('tenant_wholesale_settlements', [
            'tenant_id' => 'release-failed',
            'order_id' => $order->id,
            'status' => TenantWholesaleSettlement::STATUS_RELEASED,
        ]);
    }

    public function test_paid_order_with_a_reservation_is_completed_on_retry_without_another_debit(): void
    {
        $plan = $this->sharedPlan();
        $t = Tenant::create(['id' => 'recover-paid', 'node_ordinal' => 1]);
        app(WholesaleService::class)->credit('recover-paid', 5000);

        tenancy()->initialize($t);
        $user = User::create(['name' => 'c', 'email' => 'recover@example.com', 'password' => 'x']);
        $order = app(OrderService::class)->createOrder($user, $plan, 'month');
        $settlement = app(WholesaleService::class)->reserve($order);
        $order->update(['status' => Order::STATUS_PAID, 'paid_at' => now()]);

        app(OrderService::class)->markPaid($order->fresh());
        tenancy()->end();

        $this->assertSame(3500, app(WholesaleService::class)->balance('recover-paid'));
        $this->assertSame(
            TenantWholesaleSettlement::STATUS_COMPLETED,
            $settlement->fresh()->status,
        );
    }

    public function test_historical_paid_order_without_a_ledger_is_not_debited_again(): void
    {
        $plan = $this->sharedPlan();
        $t = Tenant::create(['id' => 'historical-paid', 'node_ordinal' => 1]);
        app(WholesaleService::class)->credit('historical-paid', 5000);

        tenancy()->initialize($t);
        $user = User::create(['name' => 'c', 'email' => 'historical@example.com', 'password' => 'x']);
        $order = app(OrderService::class)->createOrder($user, $plan, 'month');
        $order->update(['status' => Order::STATUS_PAID, 'paid_at' => now()]);

        app(OrderService::class)->markPaid($order->fresh());
        tenancy()->end();

        $this->assertSame(5000, app(WholesaleService::class)->balance('historical-paid'));
        $this->assertDatabaseMissing('tenant_wholesale_settlements', [
            'tenant_id' => 'historical-paid',
            'order_id' => $order->id,
        ]);
    }

    public function test_outer_tenant_transaction_rollback_reuses_the_reservation_on_retry(): void
    {
        Notification::fake();
        $plan = $this->sharedPlan();
        $t = Tenant::create(['id' => 'outer-rollback', 'node_ordinal' => 1]);
        app(WholesaleService::class)->credit('outer-rollback', 5000);

        tenancy()->initialize($t);
        $user = User::create(['name' => 'c', 'email' => 'rollback@example.com', 'password' => 'x']);
        $order = app(OrderService::class)->createOrder($user, $plan, 'month');

        try {
            DB::transaction(function () use ($order) {
                app(OrderService::class)->markPaid($order);
                throw new \RuntimeException('force outer rollback');
            });
            $this->fail('expected outer rollback');
        } catch (\RuntimeException $e) {
            $this->assertSame('force outer rollback', $e->getMessage());
        }

        $this->assertFalse($order->fresh()->isPaid());
        $this->assertSame(3500, app(WholesaleService::class)->balance('outer-rollback'));

        app(OrderService::class)->markPaid($order->fresh());
        $this->assertTrue($order->fresh()->isPaid());
        tenancy()->end();

        $this->assertSame(3500, app(WholesaleService::class)->balance('outer-rollback'));
        $this->assertDatabaseHas('tenant_wholesale_settlements', [
            'tenant_id' => 'outer-rollback',
            'order_id' => $order->id,
            'status' => TenantWholesaleSettlement::STATUS_COMPLETED,
        ]);
    }

    public function test_outer_tenant_transaction_commit_completes_the_reservation(): void
    {
        Notification::fake();
        $plan = $this->sharedPlan();
        $t = Tenant::create(['id' => 'outer-commit', 'node_ordinal' => 1]);
        app(WholesaleService::class)->credit('outer-commit', 5000);

        tenancy()->initialize($t);
        $user = User::create(['name' => 'c', 'email' => 'commit@example.com', 'password' => 'x']);
        $order = app(OrderService::class)->createOrder($user, $plan, 'month');

        DB::transaction(fn () => app(OrderService::class)->markPaid($order));
        $this->assertTrue($order->fresh()->isPaid());
        tenancy()->end();

        $this->assertSame(3500, app(WholesaleService::class)->balance('outer-commit'));
        $this->assertDatabaseHas('tenant_wholesale_settlements', [
            'tenant_id' => 'outer-commit',
            'order_id' => $order->id,
            'status' => TenantWholesaleSettlement::STATUS_COMPLETED,
        ]);
    }

    public function test_central_main_site_orders_ignore_wholesale(): void
    {
        Notification::fake();
        $plan = $this->sharedPlan();
        $user = User::factory()->create();

        // 主站 order (no tenant context) — delivers normally, no wholesale involved.
        $order = app(OrderService::class)->createOrder($user, $plan, 'month');
        app(OrderService::class)->markPaid($order);

        $this->assertTrue($order->fresh()->isPaid());
        $this->assertTrue($user->fresh()->hasActiveSharedPlan());
    }
}
