<?php

namespace Tests\Feature;

use App\Models\Commission;
use App\Models\Coupon;
use App\Models\InviteCode;
use App\Models\Plan;
use App\Models\Server;
use App\Models\Setting;
use App\Models\User;
use App\Services\CommissionService;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Each of the five 一线 plugin toggles must be a REAL switch: the flag is
 * consumed in business/UI code, not a dead admin card. These tests exercise
 * the consumption side of each one.
 */
class TierOnePluginToggleTest extends TestCase
{
    use RefreshDatabase;

    private function sharedPlan(int $priceCents = 3000): Plan
    {
        Server::create([
            'name' => '共享节点', 'group_id' => 1,
            'host' => '198.51.100.10', 'port' => 443, 'rate' => 1,
        ]);

        return Plan::create([
            'name' => '共享套餐', 'type' => Plan::TYPE_SHARED, 'group_id' => 1,
            'transfer_enable' => 20 * 1024 * 1024 * 1024,
            'prices' => ['month' => $priceCents], 'sell' => true,
        ]);
    }

    // --- 插件管理页面 -------------------------------------------------------

    public function test_plugins_page_lists_all_five_tier_one_features(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        Livewire::actingAs($admin)->test('admin.plugins')
            ->assertSee('开放注册')
            ->assertSee('邀请制注册')
            ->assertSee('工单系统')
            ->assertSee('优惠券')
            ->assertSee('余额支付')
            ->assertSee('邀请返佣');
    }

    // --- 开放注册 (reuses stop_register, inverted) --------------------------

    public function test_registration_card_toggles_the_stop_register_setting(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        // Registration is open by default (stop_register = false), so the card
        // shows enabled; toggling it must set stop_register = true.
        Livewire::actingAs($admin)->test('admin.plugins')
            ->call('toggleFeature', 'stop_register');

        $this->assertTrue(Setting::getBool('stop_register', false));
    }

    // --- 邀请制注册 ---------------------------------------------------------

    public function test_invite_only_registration_blocks_signup_without_a_code(): void
    {
        Setting::set('registration_invite_only', true);

        Livewire::test('auth.register')
            ->set('name', '张三')
            ->set('email', 'nocode@example.com')
            ->set('password', 'password123')
            ->set('password_confirmation', 'password123')
            ->call('register');

        $this->assertDatabaseMissing('users', ['email' => 'nocode@example.com']);
    }

    public function test_invite_only_registration_allows_signup_with_a_valid_code(): void
    {
        Setting::set('registration_invite_only', true);
        $referrer = User::factory()->create();
        $code = InviteCode::create(['user_id' => $referrer->id, 'code' => 'GOODCODE']);

        Livewire::test('auth.register')
            ->set('inviteCode', $code->code)
            ->set('name', '李四')
            ->set('email', 'withcode@example.com')
            ->set('password', 'password123')
            ->set('password_confirmation', 'password123')
            ->call('register');

        $newUser = User::where('email', 'withcode@example.com')->first();
        $this->assertNotNull($newUser);
        $this->assertSame($referrer->id, $newUser->referred_by_user_id);
    }

    public function test_normal_registration_still_works_without_a_code_when_not_invite_only(): void
    {
        Setting::set('registration_invite_only', false);

        Livewire::test('auth.register')
            ->set('name', '王五')
            ->set('email', 'open@example.com')
            ->set('password', 'password123')
            ->set('password_confirmation', 'password123')
            ->call('register');

        $this->assertDatabaseHas('users', ['email' => 'open@example.com']);
    }

    // --- 工单系统 -----------------------------------------------------------

    public function test_tickets_page_404s_when_ticket_system_disabled(): void
    {
        Setting::set('ticket_system_enabled', false);
        $user = User::factory()->create();

        $this->actingAs($user)->get('/tickets')->assertNotFound();
    }

    public function test_tickets_nav_link_hidden_when_ticket_system_disabled(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/')->assertSee('我的工单');

        Setting::set('ticket_system_enabled', false);
        $this->actingAs($user)->get('/')->assertDontSee('我的工单');
    }

    // --- 优惠券 -------------------------------------------------------------

    public function test_coupon_is_ignored_when_coupon_plugin_disabled(): void
    {
        Setting::set('coupon_enabled', false);
        $user = User::factory()->create();
        $plan = $this->sharedPlan(1500);
        Coupon::create(['code' => 'SAVE20', 'type' => 'percent', 'value' => 20, 'enabled' => true]);

        // A valid code that would normally discount ¥3 is simply ignored — the
        // order pays full price and records no coupon.
        $order = app(OrderService::class)->createOrder($user, $plan, 'month', 'SAVE20');

        $this->assertSame(0, $order->discount_amount);
        $this->assertSame(1500, $order->total_amount);
        $this->assertNull($order->coupon_id);
    }

    public function test_coupon_still_applies_when_plugin_enabled(): void
    {
        Setting::set('coupon_enabled', true);
        $user = User::factory()->create();
        $plan = $this->sharedPlan(1500);
        Coupon::create(['code' => 'SAVE20', 'type' => 'percent', 'value' => 20, 'enabled' => true]);

        $order = app(OrderService::class)->createOrder($user, $plan, 'month', 'SAVE20');

        $this->assertSame(300, $order->discount_amount);
        $this->assertSame(1200, $order->total_amount);
    }

    // --- 余额支付 -----------------------------------------------------------

    public function test_apply_balance_throws_when_balance_payment_disabled(): void
    {
        Setting::set('balance_payment_enabled', false);
        $plan = $this->sharedPlan(3000);
        $user = User::factory()->create(['balance' => 5000]);

        $order = app(OrderService::class)->createOrder($user, $plan, 'month');

        $this->expectException(ValidationException::class);
        app(OrderService::class)->applyBalance($order);
    }

    public function test_apply_balance_works_when_plugin_enabled(): void
    {
        Setting::set('balance_payment_enabled', true);
        $plan = $this->sharedPlan(3000);
        $user = User::factory()->create(['balance' => 5000]);

        $order = app(OrderService::class)->createOrder($user, $plan, 'month');
        $paid = app(OrderService::class)->applyBalance($order);

        $this->assertTrue($paid->isPaid());
        $this->assertSame(2000, $user->fresh()->balance);
    }

    // --- 邀请返佣 -----------------------------------------------------------

    public function test_no_commission_generated_when_referral_disabled(): void
    {
        Setting::set('referral_enabled', false);
        Setting::set('commission_rate', 10);

        $plan = $this->sharedPlan(3000);
        $referrer = User::factory()->create();
        $buyer = User::factory()->create(['referred_by_user_id' => $referrer->id]);

        $orderService = app(OrderService::class);
        $orderService->markPaid($orderService->createOrder($buyer, $plan, 'month'));

        $this->assertSame(0, Commission::count());
    }

    public function test_commission_generated_when_referral_enabled(): void
    {
        Setting::set('referral_enabled', true);
        Setting::set('commission_rate', 10);

        $plan = $this->sharedPlan(3000);
        $referrer = User::factory()->create();
        $buyer = User::factory()->create(['referred_by_user_id' => $referrer->id]);

        $orderService = app(OrderService::class);
        $orderService->markPaid($orderService->createOrder($buyer, $plan, 'month'));

        $this->assertGreaterThan(0, Commission::count());
    }

    public function test_invite_page_404s_when_referral_disabled(): void
    {
        Setting::set('referral_enabled', false);
        $user = User::factory()->create();

        $this->actingAs($user)->get('/invite')->assertNotFound();
    }

    public function test_invite_nav_link_hidden_when_referral_disabled(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/')->assertSee('我的邀请');

        Setting::set('referral_enabled', false);
        $this->actingAs($user)->get('/')->assertDontSee('我的邀请');
    }
}
