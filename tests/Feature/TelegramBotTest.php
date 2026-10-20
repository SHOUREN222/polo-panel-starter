<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Notifications\NewOrderPaid;
use App\Notifications\NewTicketCreated;
use App\Notifications\TicketReplied;
use App\Services\OrderService;
use App\Services\TelegramService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class TelegramBotTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::set('telegram_bot_token', 'test-token');
        Setting::set('telegram_bot_username', 'TestBot');
    }

    private function makePlan(): Plan
    {
        return Plan::create([
            'name' => '20GB 基础共享线路',
            'type' => Plan::TYPE_SHARED,
            'group_id' => 1,
            'transfer_enable' => 20 * 1024 * 1024 * 1024,
            'prices' => ['month' => 1500],
            'sell' => true,
        ]);
    }

    public function test_telegram_service_sends_message_via_bot_api(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        app(TelegramService::class)->sendMessage(12345, 'hello');

        Http::assertSent(fn ($request) => str_contains($request->url(), 'bottest-token/sendMessage')
            && $request['chat_id'] === 12345
            && $request['text'] === 'hello');
    }

    public function test_generate_bind_code_sets_code_and_expiry(): void
    {
        $user = User::factory()->create();

        $code = $user->generateTelegramBindCode();

        $this->assertSame($code, $user->fresh()->telegram_bind_code);
        $this->assertTrue($user->fresh()->telegram_bind_code_expires_at->isFuture());
    }

    public function test_bot_start_command_with_valid_code_binds_the_account(): void
    {
        Http::fake(['api.telegram.org/*' => Http::sequence()
            ->push(['ok' => true, 'result' => [
                ['update_id' => 1, 'message' => ['chat' => ['id' => 999], 'from' => ['username' => 'someone'], 'text' => '/start ABCDEFGH']],
            ]])
            ->push(['ok' => true]),
        ]);

        $user = User::factory()->create();
        $user->telegram_bind_code = 'ABCDEFGH';
        $user->telegram_bind_code_expires_at = now()->addMinutes(15);
        $user->save();

        $this->artisan('telegram:poll --once')->assertExitCode(0);

        $user->refresh();
        $this->assertSame(999, $user->telegram_id);
        $this->assertSame('someone', $user->telegram_username);
        $this->assertNull($user->telegram_bind_code);
    }

    public function test_bot_bind_command_rejects_expired_code(): void
    {
        Http::fake(['api.telegram.org/*' => Http::sequence()
            ->push(['ok' => true, 'result' => [
                ['update_id' => 1, 'message' => ['chat' => ['id' => 999], 'from' => [], 'text' => '/bind STALECODE']],
            ]])
            ->push(['ok' => true]),
        ]);

        $user = User::factory()->create();
        $user->telegram_bind_code = 'STALECODE';
        $user->telegram_bind_code_expires_at = now()->subMinute();
        $user->save();

        $this->artisan('telegram:poll --once')->assertExitCode(0);

        $this->assertNull($user->fresh()->telegram_id);
    }

    public function test_bot_status_command_replies_with_account_info_for_bound_user(): void
    {
        Http::fake(['api.telegram.org/*' => Http::sequence()
            ->push(['ok' => true, 'result' => [
                ['update_id' => 1, 'message' => ['chat' => ['id' => 555], 'from' => [], 'text' => '/status']],
            ]])
            ->push(['ok' => true]),
        ]);

        $user = User::factory()->create(['telegram_id' => 555]);

        $this->artisan('telegram:poll --once')->assertExitCode(0);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'sendMessage')
            && ($request['chat_id'] ?? null) === 555
            && str_contains($request['text'] ?? '', $user->email));
    }

    public function test_bot_getlatesturl_command_replies_with_subscription_link(): void
    {
        Http::fake(['api.telegram.org/*' => Http::sequence()
            ->push(['ok' => true, 'result' => [
                ['update_id' => 1, 'message' => ['chat' => ['id' => 556], 'from' => [], 'text' => '/getlatesturl']],
            ]])
            ->push(['ok' => true]),
        ]);

        $user = User::factory()->create(['telegram_id' => 556]);

        $this->artisan('telegram:poll --once')->assertExitCode(0);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'sendMessage')
            && ($request['chat_id'] ?? null) === 556
            && str_contains($request['text'] ?? '', $user->token));
    }

    public function test_bot_unbind_command_removes_the_binding(): void
    {
        Http::fake(['api.telegram.org/*' => Http::sequence()
            ->push(['ok' => true, 'result' => [
                ['update_id' => 1, 'message' => ['chat' => ['id' => 557], 'from' => [], 'text' => '/unbind']],
            ]])
            ->push(['ok' => true]),
        ]);

        $user = User::factory()->create(['telegram_id' => 557]);

        $this->artisan('telegram:poll --once')->assertExitCode(0);

        $this->assertNull($user->fresh()->telegram_id);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'sendMessage')
            && str_contains($request['text'] ?? '', '已解绑'));
    }

    public function test_bot_status_command_asks_unbound_chat_to_bind_first(): void
    {
        Http::fake(['api.telegram.org/*' => Http::sequence()
            ->push(['ok' => true, 'result' => [
                ['update_id' => 1, 'message' => ['chat' => ['id' => 777], 'from' => [], 'text' => '/status']],
            ]])
            ->push(['ok' => true]),
        ]);

        $this->artisan('telegram:poll --once')->assertExitCode(0);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'sendMessage')
            && str_contains($request['text'] ?? '', '还没有绑定'));
    }

    public function test_ticket_reply_notification_goes_to_telegram_when_bound(): void
    {
        Notification::fake();

        $admin = User::factory()->create(['is_admin' => true]);
        $user = User::factory()->create(['telegram_id' => 111]);
        $ticket = Ticket::create(['user_id' => $user->id, 'subject' => 'x', 'status' => 'open']);

        Livewire::actingAs($admin)->test('admin.tickets')
            ->call('select', $ticket->id)
            ->set('reply', '已处理')
            ->call('sendReply');

        Notification::assertSentTo($user, TicketReplied::class, function ($notification, $channels) {
            return in_array(\App\Notifications\Channels\TelegramChannel::class, $channels);
        });
    }

    public function test_new_paid_order_notifies_admins_telegram_first_then_email(): void
    {
        Notification::fake();

        // Both admins hear about it; the channel differs. Telegram-only used to
        // mean an admin who hadn't linked an account learned nothing at all —
        // which for a 分站 agent meant missing their own shop's sales.
        $boundAdmin = User::factory()->create(['is_admin' => true, 'telegram_id' => 222]);
        $unboundAdmin = User::factory()->create(['is_admin' => true]);
        $buyer = User::factory()->create();
        $plan = $this->makePlan();

        $orderService = app(OrderService::class);
        $order = $orderService->createOrder($buyer, $plan, 'month');
        $orderService->markPaid($order);

        Notification::assertSentTo($boundAdmin, NewOrderPaid::class, fn ($n, $channels) => in_array(\App\Notifications\Channels\TelegramChannel::class, $channels, true));
        Notification::assertSentTo($unboundAdmin, NewOrderPaid::class, fn ($n, $channels) => in_array('mail', $channels, true));
        Notification::assertCount(2);
    }

    public function test_new_ticket_notifies_telegram_bound_admins(): void
    {
        Notification::fake();

        $boundAdmin = User::factory()->create(['is_admin' => true, 'telegram_id' => 333]);
        $user = User::factory()->create();

        Livewire::actingAs($user)->test('tickets')
            ->call('startNewTicket')
            ->set('subject', '连不上')
            ->set('message', '一直超时')
            ->call('createTicket');

        Notification::assertSentTo($boundAdmin, NewTicketCreated::class);
    }

    public function test_group_web_url_converts_to_tg_app_link(): void
    {
        $svc = app(TelegramService::class);

        // private invite links
        $this->assertSame('tg://join?invite=VyOwnIOUHQg0YWQx', $svc->appLinkForGroup('https://t.me/+VyOwnIOUHQg0YWQx'));
        $this->assertSame('tg://join?invite=ABC123', $svc->appLinkForGroup('https://t.me/joinchat/ABC123'));
        // public username
        $this->assertSame('tg://resolve?domain=pologroup', $svc->appLinkForGroup('https://t.me/pologroup'));
        // non-parseable / empty
        $this->assertNull($svc->appLinkForGroup(null));
        $this->assertNull($svc->appLinkForGroup('https://example.com/chat'));
    }

    public function test_customer_profile_page_shows_bind_code_and_deep_link_after_generating(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)->test('profile')
            ->call('generateTelegramBindCode')
            ->assertSee('打开 Telegram App 绑定')
            ->assertSee('/bind '.$user->fresh()->telegram_bind_code);
    }

    public function test_admin_profile_page_shows_bind_code_and_deep_link_after_generating(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        Livewire::actingAs($admin)->test('admin.profile')
            ->call('generateTelegramBindCode')
            ->assertSee('打开 Telegram App 绑定')
            ->assertSee($admin->fresh()->telegram_bind_code);
    }

    public function test_unbind_removes_telegram_id(): void
    {
        $user = User::factory()->create(['telegram_id' => 444, 'telegram_username' => 'someone']);

        Livewire::actingAs($user)->test('profile')
            ->call('unbindTelegram')
            ->assertSet('successMessage', 'Telegram 已解绑');

        $this->assertNull($user->fresh()->telegram_id);
    }
}
