<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\SubscriptionInstance;
use App\Models\User;
use App\Notifications\Channels\TelegramChannel;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PluginToggleTest extends TestCase
{
    use RefreshDatabase;

    public function test_subscription_endpoint_returns_503_when_plugin_disabled(): void
    {
        $user = User::factory()->create();
        Setting::set('plugin.subscription_endpoint_enabled', false);

        $this->get("/subscribe/{$user->token}")->assertStatus(503);
    }

    public function test_subscription_endpoint_works_by_default(): void
    {
        $user = User::factory()->create();

        $this->get("/subscribe/{$user->token}")->assertStatus(200);
    }

    public function test_disabling_auto_provision_leaves_new_order_pending_without_ip(): void
    {
        Setting::set('plugin.auto_provision_enabled', false);

        $user = User::factory()->create();
        $plan = Plan::create([
            'name' => '静态 ISP｜北美洲｜美国｜旧金山',
            'type' => Plan::TYPE_DEDICATED_ISP,
            'transfer_enable' => 50 * 1024 * 1024 * 1024,
            'prices' => ['month' => 5500],
            'resource_profile' => ['provider' => 'stub'],
            'sell' => true,
        ]);

        $orderService = app(OrderService::class);
        $order = $orderService->createOrder($user, $plan, 'month');
        $orderService->markPaid($order);

        $instance = SubscriptionInstance::first();
        $this->assertSame(SubscriptionInstance::STATUS_PENDING, $instance->status);
        $this->assertEmpty($instance->meta);
    }

    public function test_admin_plugins_page_lists_feature_and_payment_entries(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        Payment::create(['name' => 'Coinbase 支付', 'gateway' => Payment::GATEWAY_COINBASE, 'enable' => true]);

        \Livewire\Livewire::actingAs($admin)->test('admin.plugins')
            ->assertSee('订阅端点')
            ->assertSee('Telegram Bot 集成')
            ->assertSee('Coinbase 支付')
            ->assertSee('支付方式');
    }

    public function test_admin_plugins_payment_tab_filters_to_payment_rows_only(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        Payment::create(['name' => 'Coinbase 支付', 'gateway' => Payment::GATEWAY_COINBASE, 'enable' => true]);

        \Livewire\Livewire::actingAs($admin)->test('admin.plugins')
            ->set('tab', 'payment')
            ->assertSee('Coinbase 支付')
            ->assertDontSee('订阅端点');
    }

    public function test_admin_plugins_search_filters_by_name(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        Payment::create(['name' => 'Coinbase 支付', 'gateway' => Payment::GATEWAY_COINBASE, 'enable' => true]);

        \Livewire\Livewire::actingAs($admin)->test('admin.plugins')
            ->set('search', 'Coinbase')
            ->assertSee('Coinbase 支付')
            ->assertDontSee('订阅端点');
    }

    public function test_admin_can_toggle_payment_gateway_from_plugins_page(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $payment = Payment::create(['name' => 'Coinbase 支付', 'gateway' => Payment::GATEWAY_COINBASE, 'enable' => true]);

        \Livewire\Livewire::actingAs($admin)->test('admin.plugins')
            ->call('togglePayment', $payment->id);

        $this->assertFalse($payment->fresh()->enable);
    }

    public function test_admin_can_uninstall_payment_gateway_from_plugins_page(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $payment = Payment::create(['name' => 'Coinbase 支付', 'gateway' => Payment::GATEWAY_COINBASE, 'enable' => true]);

        \Livewire\Livewire::actingAs($admin)->test('admin.plugins')
            ->call('deletePayment', $payment->id);

        $this->assertDatabaseMissing('payments', ['id' => $payment->id]);
    }

    public function test_telegram_channel_is_a_noop_when_integration_disabled(): void
    {
        Http::fake();
        Setting::set('plugin.telegram_integration_enabled', false);

        $user = User::factory()->create(['telegram_id' => 12345]);
        $notification = new class extends \Illuminate\Notifications\Notification {
            public function toTelegram(object $notifiable): string
            {
                return 'test message';
            }
        };

        app(TelegramChannel::class)->send($user, $notification);

        Http::assertNothingSent();
    }

    public function test_telegram_channel_sends_when_integration_enabled(): void
    {
        Http::fake();
        Setting::set('plugin.telegram_integration_enabled', true);
        Setting::set('telegram_bot_token', 'fake-token');

        $user = User::factory()->create(['telegram_id' => 12345]);
        $notification = new class extends \Illuminate\Notifications\Notification {
            public function toTelegram(object $notifiable): string
            {
                return 'test message';
            }
        };

        app(TelegramChannel::class)->send($user, $notification);

        Http::assertSentCount(1);
    }

    public function test_telegram_poll_command_exits_immediately_when_disabled(): void
    {
        Setting::set('telegram_bot_token', 'fake-token');
        Setting::set('plugin.telegram_integration_enabled', false);

        $this->artisan('telegram:poll', ['--once' => true])->assertSuccessful();
    }

    public function test_admin_plugins_page_lists_all_newly_surfaced_features(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        \Livewire\Livewire::actingAs($admin)->test('admin.plugins')
            ->assertSee('邮箱验证')
            ->assertSee('邮件登录')
            ->assertSee('三级分销')
            ->assertSee('套餐折抵换购')
            ->assertSee('邮件提醒');
    }

    public function test_toggling_a_feature_with_false_default_respects_that_default(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        // email_verification_enabled defaults to false — toggling once
        // should turn it ON, not OFF (which is what a blind
        // Setting::getBool($key, true) would incorrectly do).
        \Livewire\Livewire::actingAs($admin)->test('admin.plugins')
            ->call('toggleFeature', 'email_verification_enabled');

        $this->assertTrue(Setting::getBool('email_verification_enabled', false));
    }

    public function test_magic_login_plugin_entry_gates_the_real_feature(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        \Livewire\Livewire::actingAs($admin)->test('admin.plugins')
            ->call('toggleFeature', 'magic_login_enabled');

        $this->assertFalse(Setting::getBool('magic_login_enabled', true));

        \Illuminate\Support\Facades\Auth::logout();
        $this->get('/login-by-email')->assertNotFound();
    }
}
