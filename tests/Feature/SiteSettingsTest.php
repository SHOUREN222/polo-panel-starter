<?php

namespace Tests\Feature;

use App\Jobs\SyncAppClientFromGithub;
use App\Models\AppClient;
use App\Models\Server;
use App\Models\Setting;
use App\Models\User;
use App\Providers\AppServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class SiteSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_setting_get_returns_default_when_missing(): void
    {
        $this->assertSame('fallback', Setting::get('nope', 'fallback'));
    }

    public function test_setting_set_then_get_roundtrips(): void
    {
        Setting::set('site_name', 'Polo 全球网络服务');

        $this->assertSame('Polo 全球网络服务', Setting::get('site_name'));
    }

    public function test_admin_can_update_site_settings(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $component = Livewire::actingAs($admin)->test('admin.system');
        $component->set('site_name', '新站点名')
            ->set('stop_register', true)
            ->call('save');

        $this->assertSame('新站点名', Setting::get('site_name'));
        $this->assertTrue(Setting::getBool('stop_register'));
    }

    public function test_admin_can_update_logo_and_subscribe_url(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        Livewire::actingAs($admin)->test('admin.system')
            ->set('site_logo_url', 'https://cdn.example.com/logo.png/')
            ->set('subscribe_url', 'https://sub.example.com/')
            ->call('save');

        $this->assertSame('https://cdn.example.com/logo.png', Setting::get('site_logo_url'));
        $this->assertSame('https://sub.example.com', Setting::get('subscribe_url'));
    }

    public function test_subscription_url_uses_subscribe_url_setting_when_configured(): void
    {
        Setting::set('subscribe_url', 'https://sub.example.com');
        $user = User::factory()->create(['token' => 'abc123']);

        $this->assertSame('https://sub.example.com/subscribe/abc123', $user->subscriptionUrl());
    }

    public function test_subscription_url_falls_back_to_default_route_without_setting(): void
    {
        $user = User::factory()->create(['token' => 'abc123']);

        $this->assertSame(route('subscribe', ['token' => 'abc123']), $user->subscriptionUrl());
    }

    public function test_subscription_url_honors_custom_subscribe_path_alongside_custom_domain(): void
    {
        Setting::set('subscribe_url', 'https://sub.example.com');
        Setting::set('subscribe_path', 'sub');
        $user = User::factory()->create(['token' => 'abc123']);

        $this->assertSame('https://sub.example.com/sub/abc123', $user->subscriptionUrl());
    }

    public function test_site_logo_appears_in_layout_when_configured(): void
    {
        Setting::set('site_logo_url', 'https://cdn.example.com/logo.png');
        $user = User::factory()->create();

        $this->actingAs($user)->get('/')
            ->assertOk()
            ->assertSee('https://cdn.example.com/logo.png', false);
    }

    public function test_system_settings_page_lists_all_tabs(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->get('/'.config('admin.path').'/system')
            ->assertOk()
            ->assertSee('站点设置')
            ->assertSee('安全设置')
            ->assertSee('订阅设置')
            ->assertSee('邀请&佣金设置')
            ->assertSee('节点配置')
            ->assertSee('邮件设置')
            ->assertSee('Telegram 设置')
            ->assertSee('APP 设置')
            ->assertSee('订阅模板');
    }

    public function test_admin_can_update_mail_settings(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        Livewire::actingAs($admin)->test('admin.system')
            ->set('mail_host', 'smtp.zoho.com')
            ->set('mail_port', '465')
            ->set('mail_encryption', 'ssl')
            ->set('mail_username', 'bot@example.com')
            ->set('mail_password', 'secret')
            ->set('mail_from_address', 'bot@example.com')
            ->set('mail_reminders_enabled', false)
            ->call('saveMail');

        $this->assertTrue(Setting::getBool('mail_smtp_configured'));
        $this->assertSame('smtp.zoho.com', Setting::get('mail_host'));
        $this->assertSame(465, (int) Setting::get('mail_port'));
        $this->assertFalse(Setting::getBool('mail_reminders_enabled'));
    }

    public function test_configured_smtp_settings_override_mail_config_at_runtime(): void
    {
        Setting::set('mail_smtp_configured', true);
        Setting::set('mail_host', 'smtp.example-custom.com');
        Setting::set('mail_port', 2525);
        Setting::set('mail_encryption', 'ssl');
        Setting::set('mail_username', 'custom-user');
        Setting::set('mail_password', 'custom-pass');
        Setting::set('mail_from_address', 'custom@example.com');

        (new AppServiceProvider($this->app))->boot();

        $this->assertSame('smtp', config('mail.default'));
        $this->assertSame('smtp.example-custom.com', config('mail.mailers.smtp.host'));
        $this->assertSame(2525, config('mail.mailers.smtp.port'));
        $this->assertSame('smtps', config('mail.mailers.smtp.scheme'));
        $this->assertSame('custom@example.com', config('mail.from.address'));
    }

    public function test_admin_can_add_and_remove_recommended_app_clients(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $component = Livewire::actingAs($admin)->test('admin.system')
            ->set('newAppClientPlatform', AppClient::PLATFORM_WINDOWS)
            ->set('newAppClientName', 'Clash Verge')
            ->set('newAppClientUrl', 'https://github.com/clash-verge-rev/clash-verge-rev/releases')
            ->call('addAppClient');

        $this->assertDatabaseHas('app_clients', [
            'platform' => 'windows',
            'name' => 'Clash Verge',
            'url' => 'https://github.com/clash-verge-rev/clash-verge-rev/releases',
        ]);

        $client = AppClient::first();
        $component->call('removeAppClient', $client->id);

        $this->assertDatabaseMissing('app_clients', ['id' => $client->id]);
    }

    public function test_admin_can_add_multiple_clients_for_the_same_platform(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        Livewire::actingAs($admin)->test('admin.system')
            ->set('newAppClientPlatform', AppClient::PLATFORM_IOS)
            ->set('newAppClientName', 'Shadowrocket')
            ->set('newAppClientUrl', 'https://apps.apple.com/app/id932747118')
            ->call('addAppClient')
            ->set('newAppClientPlatform', AppClient::PLATFORM_IOS)
            ->set('newAppClientName', 'Stash')
            ->set('newAppClientUrl', 'https://apps.apple.com/app/id1596063349')
            ->call('addAppClient');

        $this->assertSame(2, AppClient::where('platform', 'ios')->count());
    }

    public function test_node_comm_key_is_auto_generated_and_can_be_saved(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $component = Livewire::actingAs($admin)->test('admin.system');

        $this->assertNotEmpty(Setting::get('node_comm_key'));

        $component->set('node_pull_interval', '30')
            ->set('node_push_interval', '45')
            ->call('saveNode');

        $this->assertSame(30, (int) Setting::get('node_pull_interval'));
        $this->assertSame(45, (int) Setting::get('node_push_interval'));
    }

    public function test_node_config_endpoint_requires_valid_key(): void
    {
        config(['security.allow_legacy_node_query_token' => false]);
        Setting::set('node_comm_key', 'real-key');

        $this->getJson('/api/nodes/config?key=wrong')->assertStatus(403);
        $this->getJson('/api/nodes/config?key=real-key')->assertStatus(403);
        $this->getJson('/api/nodes/config', ['X-Node-Key' => 'wrong'])->assertStatus(403);
        // No key at all is rejected, and never treated as valid against an
        // empty configured key.
        $this->getJson('/api/nodes/config')->assertStatus(403);
    }

    public function test_node_config_endpoint_accepts_key_via_header(): void
    {
        Setting::set('node_comm_key', 'real-key');
        Server::create(['name' => '测试节点', 'group_id' => 1, 'host' => '1.2.3.4', 'port' => 443, 'rate' => 1]);

        // Header path keeps the key out of the URL / access log.
        $this->getJson('/api/nodes/config', ['X-Node-Key' => 'real-key'])
            ->assertOk()
            ->assertJsonCount(1, 'servers');
    }

    public function test_node_config_endpoint_returns_server_list_with_valid_key(): void
    {
        Setting::set('node_comm_key', 'real-key');
        Setting::set('node_pull_interval', 30);
        Server::create(['name' => '测试节点', 'group_id' => 1, 'host' => '1.2.3.4', 'port' => 443, 'rate' => 1]);

        $this->getJson('/api/nodes/config', ['X-Node-Key' => 'real-key'])
            ->assertOk()
            ->assertJsonPath('pull_interval', 30)
            ->assertJsonCount(1, 'servers')
            ->assertJsonPath('servers.0.name', '测试节点');
    }

    public function test_node_config_query_key_requires_the_temporary_migration_switch(): void
    {
        config(['security.allow_legacy_node_query_token' => true]);
        Setting::set('node_comm_key', 'real-key');

        $this->getJson('/api/nodes/config?key=real-key')->assertOk();
    }

    public function test_admin_can_update_security_gap_fill_fields(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        Livewire::actingAs($admin)->test('admin.system')
            ->set('admin_path', 'my-custom-path')
            ->set('email_whitelist_enabled', true)
            ->set('email_whitelist', 'gmail.com,outlook.com')
            ->set('captcha_enabled', true)
            ->set('ip_register_limit_enabled', true)
            ->set('ip_register_max_attempts', '3')
            ->set('ip_register_lockout_minutes', '30')
            ->set('email_verification_enabled', true)
            ->set('block_gmail_alias', true)
            ->set('safe_mode_enabled', true)
            ->call('saveSecurity');

        $this->assertSame('my-custom-path', Setting::get('admin_path'));
        $this->assertTrue(Setting::getBool('email_whitelist_enabled'));
        $this->assertSame('gmail.com,outlook.com', Setting::get('email_whitelist'));
        $this->assertTrue(Setting::getBool('captcha_enabled'));
        $this->assertTrue(Setting::getBool('ip_register_limit_enabled'));
        $this->assertSame(3, (int) Setting::get('ip_register_max_attempts'));
        $this->assertTrue(Setting::getBool('email_verification_enabled'));
        $this->assertTrue(Setting::getBool('block_gmail_alias'));
        $this->assertTrue(Setting::getBool('safe_mode_enabled'));
    }

    public function test_saving_provision_channel_only_accepts_registered_drivers(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        // The registered driver saves fine.
        Livewire::actingAs($admin)->test('admin.system')
            ->set('provisioner_driver', 'onip')
            ->set('onip_app_id', 'id')
            ->set('onip_app_secret', 'secret')
            ->call('saveProvision')
            ->assertHasNoErrors('provisioner_driver');
        $this->assertSame('onip', Setting::get('provisioner_driver'));

        // An unregistered driver (e.g. the retired 'stub', or a typo) is rejected.
        Livewire::actingAs($admin)->test('admin.system')
            ->set('provisioner_driver', 'stub')
            ->call('saveProvision')
            ->assertHasErrors('provisioner_driver');
    }

    public function test_onip_test_connection_success_sets_the_green_message(): void
    {
        Setting::set('onip_app_id', 'id');
        Setting::set('onip_app_secret', 'secret');
        Http::fake(fn () => Http::response([
            'success' => true,
            'data' => ['username' => 'acct1', 'balance' => 420, 'currency' => 'CNY'],
        ]));

        Livewire::actingAs(User::factory()->create(['is_admin' => true]))->test('admin.system')
            ->call('testOnipConnection')
            ->assertSet('errorMessage', '')
            ->assertSet('successMessage', fn ($v) => str_contains($v, '连接成功'));
    }

    public function test_onip_test_connection_failure_uses_the_red_error_channel_not_green_success(): void
    {
        Setting::set('onip_app_id', 'id');
        Setting::set('onip_app_secret', 'secret');
        Http::fake(fn () => Http::response(['success' => false, 'code' => 'BAD', 'message' => 'nope']));

        // A failed test must not render as a green success (the old bug).
        Livewire::actingAs(User::factory()->create(['is_admin' => true]))->test('admin.system')
            ->call('testOnipConnection')
            ->assertSet('successMessage', '')
            ->assertSet('errorMessage', fn ($v) => str_contains($v, '连接失败'));
    }

    public function test_provision_channel_becomes_a_dropdown_once_a_second_provider_is_registered(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        // With one provider it's a fixed label; register a second and the
        // selector must surface both — this is the extensibility contract for
        // adding another ISP API vendor.
        Livewire::actingAs($admin)->test('admin.system')
            ->set('provisionDrivers', ['onip' => 'onip.cc 真实静态代理', 'acme' => 'Acme 静态代理'])
            ->assertSee('onip.cc 真实静态代理')
            ->assertSee('Acme 静态代理');
    }

    public function test_admin_can_send_a_test_email_using_the_current_form_values(): void
    {
        Mail::fake();
        $admin = User::factory()->create(['is_admin' => true, 'email' => 'boss@example.com']);

        Livewire::actingAs($admin)->test('admin.system')
            ->set('mail_host', 'smtp.example.com')
            ->set('mail_port', '465')
            ->set('mail_encryption', 'ssl')
            ->set('mail_username', 'u')
            ->set('mail_password', 'p')
            ->set('mail_from_address', 'from@example.com')
            ->call('sendTestMail')
            ->assertSet('errorMessage', '')
            ->assertSet('successMessage', fn ($v) => str_contains($v, 'boss@example.com'));
    }

    public function test_send_test_email_validates_required_smtp_fields(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        Livewire::actingAs($admin)->test('admin.system')
            ->set('mail_host', '')
            ->call('sendTestMail')
            ->assertHasErrors('mail_host');
    }

    public function test_sync_all_app_clients_dispatches_a_job_for_every_github_client_only(): void
    {
        Queue::fake();

        AppClient::create(['platform' => 'windows', 'name' => 'A', 'source_type' => 'github', 'github_repo' => 'a/b', 'asset_pattern' => '*.exe', 'url' => '']);
        AppClient::create(['platform' => 'macos', 'name' => 'B', 'source_type' => 'github', 'github_repo' => 'c/d', 'asset_pattern' => '*.dmg', 'url' => '']);
        // A manual-URL client must NOT be synced.
        AppClient::create(['platform' => 'windows', 'name' => 'C', 'source_type' => 'manual', 'url' => 'https://example.com/app.exe']);

        Livewire::actingAs(User::factory()->create(['is_admin' => true]))->test('admin.system')
            ->call('syncAllAppClients')
            ->assertSet('successMessage', fn ($v) => str_contains($v, '2'));

        Queue::assertPushed(SyncAppClientFromGithub::class, 2);
    }

    public function test_admin_can_save_the_app_download_note(): void
    {
        Livewire::actingAs(User::factory()->create(['is_admin' => true]))->test('admin.system')
            ->set('app_download_note', 'iOS 需美区 Apple ID，找客服领取')
            ->call('saveAppDownloadNote')
            ->assertSet('successMessage', fn ($v) => str_contains($v, '下载说明'));

        $this->assertSame('iOS 需美区 Apple ID，找客服领取', Setting::get('app_download_note'));
    }

    public function test_app_download_note_renders_in_the_customer_download_modal(): void
    {
        Setting::set('app_download_note', 'iOS 需美区 Apple ID，找客服领取');
        AppClient::create(['platform' => 'windows', 'name' => 'Clash', 'source_type' => 'manual', 'url' => 'https://example.com/clash']);

        Livewire::actingAs(User::factory()->create())->test('dashboard')
            ->assertSee('iOS 需美区 Apple ID，找客服领取');
    }

    public function test_admin_can_edit_a_per_client_download_note_and_customer_sees_it(): void
    {
        $client = AppClient::create(['platform' => 'ios', 'name' => 'Shadowrocket', 'source_type' => 'manual', 'url' => 'https://example.com/sr']);

        // Admin edits the inline per-client note.
        Livewire::actingAs(User::factory()->create(['is_admin' => true]))->test('admin.system')
            ->set("appClientNotes.{$client->id}", '付费·需美区 Apple ID')
            ->call('saveAppClientNote', $client->id)
            ->assertSet('successMessage', fn ($v) => str_contains($v, '客户端说明'));

        $this->assertSame('付费·需美区 Apple ID', $client->fresh()->note);

        // Customer sees the small note under the app in the download modal.
        Livewire::actingAs(User::factory()->create())->test('dashboard')
            ->assertSee('付费·需美区 Apple ID');
    }

    public function test_admin_can_update_subscribe_settings(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        Livewire::actingAs($admin)->test('admin.system')
            ->set('subscribe_path', 'sub')
            ->set('subscribe_show_userinfo', false)
            ->set('subscribe_show_protocol_name', true)
            ->call('saveSubscribe');

        $this->assertSame('sub', Setting::get('subscribe_path'));
        $this->assertFalse(Setting::getBool('subscribe_show_userinfo'));
        $this->assertTrue(Setting::getBool('subscribe_show_protocol_name'));
    }

    public function test_admin_can_update_the_new_subscribe_settings_added_for_xboard_parity(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        Livewire::actingAs($admin)->test('admin.system')
            ->set('subscribe_allow_plan_change', false)
            ->call('saveSubscribe');

        $this->assertFalse(Setting::getBool('subscribe_allow_plan_change'));
    }

    public function test_admin_can_update_max_invite_codes(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        Livewire::actingAs($admin)->test('admin.system')
            ->set('commission_rate', '10')
            ->set('commission_hold_days', '0')
            ->set('max_invite_codes', '3')
            ->call('saveCommission');

        $this->assertSame(3, (int) Setting::get('max_invite_codes'));
    }

    public function test_custom_site_name_appears_in_customer_layout_instead_of_app_name(): void
    {
        Setting::set('site_name', '测试站点');
        $user = User::factory()->create();

        $this->actingAs($user)->get('/')
            ->assertOk()
            ->assertSee('测试站点')
            ->assertDontSee('Laravel');
    }

    public function test_custom_site_name_appears_in_admin_layout_instead_of_app_name(): void
    {
        Setting::set('site_name', '测试站点');
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->get('/'.config('admin.path').'/plans')
            ->assertOk()
            ->assertSee('测试站点')
            ->assertDontSee('Laravel');
    }

    public function test_registration_is_blocked_when_stopped(): void
    {
        Setting::set('stop_register', true);

        $this->postJson('/api/register', [
            'name' => 'Test User',
            'email' => 'blocked@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertStatus(403);

        $this->assertDatabaseMissing('users', ['email' => 'blocked@example.com']);
    }

    public function test_admin_can_save_telegram_bot_settings(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        Livewire::actingAs($admin)->test('admin.system')
            ->set('telegram_group_url', 'https://t.me/example_group')
            ->set('telegram_bot_token', '123456:fake-token')
            ->set('telegram_bot_username', '@example_bot')
            ->call('saveTelegram');

        $this->assertSame('https://t.me/example_group', Setting::get('telegram_group_url'));
        $this->assertSame('123456:fake-token', Setting::get('telegram_bot_token'));
        // Leading @ is stripped since TelegramService builds t.me links itself.
        $this->assertSame('example_bot', Setting::get('telegram_bot_username'));
    }
}
