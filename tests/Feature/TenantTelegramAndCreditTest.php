<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\WholesaleCreditExhausted;
use App\Services\OrderService;
use App\Services\WholesaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Two gaps that only showed up once 分站 were real:
 *  - the Telegram bot is one central process but customers live in per-分站
 *    databases, so every lookup has to sweep them;
 *  - an agent whose 进货余额 ran dry had no way of knowing until a customer
 *    complained, and the customer was shown the platform's internals.
 */
class TenantTelegramAndCreditTest extends TestCase
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

    private function bot(): \App\Console\Commands\TelegramBotPoll
    {
        return app(\App\Console\Commands\TelegramBotPoll::class);
    }

    /** Reach the private cross-site helper the bot's lookups all funnel through. */
    private function findAcrossSites(\Closure $find): ?User
    {
        $found = null;
        $m = new \ReflectionMethod(\App\Console\Commands\TelegramBotPoll::class, 'onUserSite');
        $m->setAccessible(true);
        $m->invoke($this->bot(), $find, function (User $u) use (&$found) {
            $found = $u;
        });

        return $found;
    }

    public function test_bot_finds_a_tenant_customer_not_just_central_ones(): void
    {
        $t = Tenant::create(['id' => 'tgshop']);
        // Use the model's own generator — telegram_* fields are deliberately
        // not mass-assignable, so a plain update() would silently do nothing.
        $code = $t->run(fn () => User::create([
            'name' => '分站客户', 'email' => 'tg@example.com', 'password' => 'x',
        ])->generateTelegramBindCode());

        $found = $this->findAcrossSites(fn () => User::where('telegram_bind_code', $code)
            ->where('telegram_bind_code_expires_at', '>', now())->first());

        $this->assertNotNull($found, '分站客户的绑定码必须能被找到');
        $this->assertSame('tg@example.com', $found->email);
    }

    public function test_a_central_customer_still_resolves_and_short_circuits(): void
    {
        // 主站 must keep working exactly as before — and be matched first.
        $central = User::factory()->create(['telegram_id' => 555]);
        Tenant::create(['id' => 'other'])->run(fn () => User::create([
            'name' => 'x', 'email' => 'x@example.com', 'password' => 'x', 'telegram_id' => 555,
        ]));

        $found = $this->findAcrossSites(fn () => User::where('telegram_id', 555)->first());

        $this->assertSame($central->email, $found->email, '主站应优先命中');
    }

    public function test_lookup_returns_nothing_when_no_site_has_the_user(): void
    {
        Tenant::create(['id' => 'empty']);

        $this->assertNull($this->findAcrossSites(fn () => User::where('telegram_id', 999)->first()));
    }

    public function test_out_of_credit_alerts_the_agent_and_hides_platform_details(): void
    {
        Notification::fake();

        $plan = Plan::create([
            'name' => '共享', 'type' => Plan::TYPE_SHARED, 'group_id' => 1,
            'transfer_enable' => 1024, 'prices' => ['month' => 1500], 'sell' => true,
        ]);

        $t = Tenant::create(['id' => 'brokeshop', 'agent_discount' => 20]);
        // Deliberately no 进货余额.

        tenancy()->initialize($t);
        $agent = User::create(['name' => '代理', 'email' => 'agent@example.com', 'password' => 'x']);
        $agent->forceFill(['is_admin' => true])->save();
        $customer = User::create(['name' => 'c', 'email' => 'c@example.com', 'password' => 'x']);
        $order = Order::create([
            'user_id' => $customer->id, 'plan_id' => $plan->id, 'type' => Order::TYPE_NEW,
            'period' => 'month', 'trade_no' => 'T-BROKE', 'total_amount' => 1500, 'status' => Order::STATUS_PENDING,
        ]);

        try {
            app(WholesaleService::class)->assertCanSettle($order);
            $this->fail('余额不足时应当拦下交付');
        } catch (ValidationException $e) {
            $message = $e->errors()['wholesale'][0];
            // The agent's customer must not learn there's a platform behind the shop.
            $this->assertStringNotContainsString('进货余额', $message);
            $this->assertStringNotContainsString('站长', $message);
            $this->assertStringContainsString('联系客服', $message);
        }

        tenancy()->end();

        Notification::assertSentTo($agent, WholesaleCreditExhausted::class);
    }

    public function test_the_agent_is_not_re_alerted_on_every_failed_order(): void
    {
        Notification::fake();

        $plan = Plan::create([
            'name' => '共享', 'type' => Plan::TYPE_SHARED, 'group_id' => 1,
            'transfer_enable' => 1024, 'prices' => ['month' => 1500], 'sell' => true,
        ]);
        $t = Tenant::create(['id' => 'spamshop', 'agent_discount' => 20]);

        tenancy()->initialize($t);
        $agent = User::create(['name' => '代理', 'email' => 'a@example.com', 'password' => 'x']);
        $agent->forceFill(['is_admin' => true])->save();
        $customer = User::create(['name' => 'c', 'email' => 'c@example.com', 'password' => 'x']);

        foreach (['T-1', 'T-2', 'T-3'] as $no) {
            $order = Order::create([
                'user_id' => $customer->id, 'plan_id' => $plan->id, 'type' => Order::TYPE_NEW,
                'period' => 'month', 'trade_no' => $no, 'total_amount' => 1500, 'status' => Order::STATUS_PENDING,
            ]);
            try {
                app(WholesaleService::class)->assertCanSettle($order);
            } catch (ValidationException) {
            }
        }
        tenancy()->end();

        Notification::assertSentToTimes($agent, WholesaleCreditExhausted::class, 1);
    }

    public function test_a_funded_tenant_delivers_and_alerts_nobody(): void
    {
        Notification::fake();

        $plan = Plan::create([
            'name' => '共享', 'type' => Plan::TYPE_SHARED, 'group_id' => 1,
            'transfer_enable' => 100 * 1024 * 1024 * 1024, 'prices' => ['month' => 1500], 'sell' => true,
        ]);
        $t = Tenant::create(['id' => 'okshop', 'agent_discount' => 20]);
        app(WholesaleService::class)->credit('okshop', 100000);

        tenancy()->initialize($t);
        $agent = User::create(['name' => '代理', 'email' => 'a@example.com', 'password' => 'x']);
        $agent->forceFill(['is_admin' => true])->save();
        $customer = User::create(['name' => 'c', 'email' => 'c@example.com', 'password' => 'x']);
        app(OrderService::class)->markPaid(app(OrderService::class)->createOrder($customer, $plan, 'month'));
        $delivered = $customer->fresh()->hasActiveSharedPlan();
        tenancy()->end();

        $this->assertTrue($delivered);
        Notification::assertNotSentTo($agent, WholesaleCreditExhausted::class);
        // ¥15 sale consumes ¥15 of balance — the 20% was taken at top-up.
        $this->assertSame(100000 - 1500, app(WholesaleService::class)->balance('okshop'));
    }

    /** Point the bot at a given site's bot, the way pollBot() does. */
    private function withScope(?Tenant $scope, \Closure $fn)
    {
        $bot = $this->bot();
        $r = new \ReflectionProperty(\App\Console\Commands\TelegramBotPoll::class, 'scope');
        $r->setAccessible(true);
        $r->setValue($bot, $scope);

        $m = new \ReflectionMethod(\App\Console\Commands\TelegramBotPoll::class, 'onUserSite');
        $m->setAccessible(true);

        $found = null;
        // NB: a regular closure, not an arrow fn — arrow fns capture by value,
        // so a nested `use (&$found)` would bind to a copy and the result would
        // never escape (and every assertNull would pass for the wrong reason).
        $run = function () use ($m, $bot, $fn, &$found) {
            $m->invoke($bot, $fn, function (User $u) use (&$found) {
                $found = $u;
            });
        };
        $scope ? $scope->run($run) : $run();

        return $found;
    }

    public function test_an_agents_own_bot_cannot_reach_another_agents_customers(): void
    {
        // The sharp edge: the platform bot sweeps every 分站 that has no bot of
        // its own, so without a scope guard an agent's bot would inherit that
        // sweep and reach a *different* shop's customers. (It can't reach 主站
        // either, but that falls out of running inside the tenant — this is the
        // case that actually needs the guard.)
        $mine = Tenant::create(['id' => 'agent-a']);
        $mine->run(fn () => Setting::set('telegram_bot_token', '123:A-TOKEN'));

        $theirs = Tenant::create(['id' => 'agent-b']); // no bot of its own
        $theirs->run(fn () => User::create([
            'name' => 'B的客户', 'email' => 'b-cust@example.com', 'password' => 'x',
        ])->bindTelegram(777, 'bcust'));

        $found = $this->withScope($mine, fn () => User::where('telegram_id', 777)->first());

        $this->assertNull($found, '代理A的机器人不得查到代理B的客户');
    }

    public function test_an_agents_own_bot_cannot_reach_main_site_customers(): void
    {
        User::factory()->create(['telegram_id' => 778]);

        $t = Tenant::create(['id' => 'ownbot']);
        $t->run(fn () => Setting::set('telegram_bot_token', '123:AGENT-TOKEN'));

        $this->assertNull(
            $this->withScope($t, fn () => User::where('telegram_id', 778)->first()),
            '代理的机器人不得查到主站客户'
        );
    }

    public function test_an_agents_own_bot_serves_its_own_customers(): void
    {
        $t = Tenant::create(['id' => 'ownbot2']);
        $t->run(function () {
            Setting::set('telegram_bot_token', '123:AGENT-TOKEN');
            User::create(['name' => 'c', 'email' => 'c@example.com', 'password' => 'x'])
                ->bindTelegram(888, 'cust');
        });

        $found = $this->withScope($t, fn () => User::where('telegram_id', 888)->first());

        $this->assertNotNull($found);
        $this->assertSame('c@example.com', $found->email);
    }

    public function test_platform_bot_skips_tenants_that_run_their_own(): void
    {
        // A 分站 with its own bot is answered by that bot, so the platform bot
        // must not also pick its customers up (two bots, one account).
        $withBot = Tenant::create(['id' => 'hasbot']);
        $withBot->run(function () {
            Setting::set('telegram_bot_token', '123:AGENT-TOKEN');
            User::create(['name' => 'a', 'email' => 'a@example.com', 'password' => 'x'])
                ->bindTelegram(999, 'a');
        });

        $found = $this->withScope(null, fn () => User::where('telegram_id', 999)->first());

        $this->assertNull($found, '平台机器人不该接管已自建机器人的分站');
    }

    public function test_platform_bot_still_covers_tenants_without_a_bot(): void
    {
        $noBot = Tenant::create(['id' => 'nobot']);
        $noBot->run(fn () => User::create(['name' => 'b', 'email' => 'b@example.com', 'password' => 'x'])
            ->bindTelegram(1010, 'b'));

        $found = $this->withScope(null, fn () => User::where('telegram_id', 1010)->first());

        $this->assertNotNull($found, '没自建机器人的分站要由平台机器人兜底');
        $this->assertSame('b@example.com', $found->email);
    }

    public function test_each_bot_keeps_its_own_poll_cursor(): void
    {
        // Sharing one offset would make two bots swallow each other's updates.
        Setting::set('telegram_poll_offset', '100');
        $t = Tenant::create(['id' => 'cursor']);
        $t->run(fn () => Setting::set('telegram_poll_offset', '500'));

        $this->assertSame('100', Setting::get('telegram_poll_offset'));
        $this->assertSame('500', $t->run(fn () => Setting::get('telegram_poll_offset')));
    }

    public function test_a_tenant_bot_token_does_not_leak_into_the_platform_bot(): void
    {
        Setting::set('telegram_bot_token', 'PLATFORM-TOKEN');
        $t = Tenant::create(['id' => 'tok']);
        $t->run(fn () => Setting::set('telegram_bot_token', 'AGENT-TOKEN'));

        $this->assertSame('PLATFORM-TOKEN', Setting::get('telegram_bot_token'));
        $this->assertSame('AGENT-TOKEN', $t->run(fn () => Setting::get('telegram_bot_token')));
    }

    public function test_a_tenant_without_its_own_bot_is_not_mistaken_for_having_one(): void
    {
        // Regression: the bot token used to fall back to the platform's, so
        // EVERY 分站 looked like it ran its own bot. The platform bot then
        // stopped covering them (their customers silently lost Telegram) while
        // each polled the platform's own token on a separate cursor, eating one
        // another's updates.
        Setting::set('telegram_bot_token', 'PLATFORM-TOKEN');
        Setting::set('telegram_bot_username', 'PlatformBot');

        $t = Tenant::create(['id' => 'nobotyet']);

        $t->run(function () {
            $this->assertNull(Setting::get('telegram_bot_token'), '没配就该是空，不能继承平台的');
            $this->assertNull(Setting::get('telegram_bot_username'));
        });

        // …and the platform bot therefore still covers it.
        $t->run(fn () => User::create(['name' => 'c', 'email' => 'c@example.com', 'password' => 'x'])
            ->bindTelegram(4242, 'c'));

        $this->assertNotNull(
            $this->withScope(null, fn () => User::where('telegram_id', 4242)->first()),
            '没自建机器人的分站必须仍由平台机器人兜底'
        );
    }
}
