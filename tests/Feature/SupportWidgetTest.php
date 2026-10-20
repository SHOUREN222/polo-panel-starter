<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Support\SupportContacts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SupportWidgetTest extends TestCase
{
    use RefreshDatabase;

    public function test_widget_hidden_when_disabled_even_if_channels_are_filled(): void
    {
        Setting::set('support_widget_enabled', false);
        Setting::set('support_telegram', '@PoloSupport');

        $response = $this->get('/login');

        $response->assertOk();
        $response->assertDontSeeText('联系客服');
    }

    public function test_widget_hidden_when_enabled_but_no_channel_configured(): void
    {
        Setting::set('support_widget_enabled', true);

        $response = $this->get('/login');

        $response->assertOk();
        $response->assertDontSeeText('联系客服');
    }

    public function test_widget_shows_on_the_guest_login_page_for_visitors(): void
    {
        Setting::set('support_widget_enabled', true);
        Setting::set('support_telegram', '@PoloSupport');
        Setting::set('support_note', '工作时间 10:00-22:00');

        $response = $this->get('/login');

        $response->assertOk();
        // Reachable before login — this was the whole gap.
        $response->assertSeeText('联系客服');
        $response->assertSeeText('工作时间 10:00-22:00');
        // tg:// deep link so it opens the app even where t.me is blocked.
        $response->assertSee('tg://resolve?domain=PoloSupport', false);
    }

    public function test_widget_shows_for_authenticated_customers(): void
    {
        Setting::set('support_widget_enabled', true);
        Setting::set('support_email', 'help@example.com');

        $response = $this->actingAs(User::factory()->create())->get('/');

        $response->assertOk();
        $response->assertSeeText('联系客服');
        $response->assertSee('mailto:help@example.com', false);
    }

    public function test_only_configured_channels_appear(): void
    {
        Setting::set('support_widget_enabled', true);
        Setting::set('support_email', 'help@example.com');
        // Telegram/WeChat/QQ left blank.

        $response = $this->actingAs(User::factory()->create())->get('/');

        $response->assertSeeText('邮箱客服');
        $response->assertDontSeeText('Telegram 客服');
        $response->assertDontSeeText('微信');
        $response->assertDontSeeText('QQ');
    }

    public function test_wechat_shows_with_only_a_qr_and_no_id(): void
    {
        Setting::set('support_widget_enabled', true);
        Setting::set('support_wechat', '');
        Setting::set('support_wechat_qr', 'support/wechat.png');

        $items = SupportContacts::all();

        $this->assertCount(1, $items);
        $this->assertSame('微信', $items[0]['label']);
        $this->assertSame('点击扫码添加', $items[0]['value']);
        $this->assertArrayNotHasKey('copy', $items[0]);
        $this->assertStringContainsString('support/wechat.png', $items[0]['qr']);
    }

    public function test_wechat_offers_both_copy_and_qr_when_both_set(): void
    {
        Setting::set('support_widget_enabled', true);
        Setting::set('support_wechat', 'PoloVPN');
        Setting::set('support_wechat_qr', 'support/wechat.png');

        $items = SupportContacts::all();

        $this->assertSame('PoloVPN', $items[0]['copy']);
        $this->assertStringContainsString('support/wechat.png', $items[0]['qr']);
    }

    public function test_wechat_and_qq_absent_when_neither_id_nor_qr_set(): void
    {
        Setting::set('support_widget_enabled', true);
        Setting::set('support_email', 'help@example.com');

        $labels = array_column(SupportContacts::all(), 'label');

        $this->assertContains('邮箱客服', $labels);
        $this->assertNotContains('微信', $labels);
        $this->assertNotContains('QQ', $labels);
    }

    #[DataProvider('telegramInputs')]
    public function test_telegram_input_normalizes_to_a_tg_app_link(string $input, string $expectedAppHref, string $expectedDisplay): void
    {
        Setting::set('support_widget_enabled', true);
        Setting::set('support_telegram', $input);

        $items = SupportContacts::all();

        $this->assertCount(1, $items);
        $this->assertSame($expectedAppHref, $items[0]['href']);
        $this->assertSame($expectedDisplay, $items[0]['value']);
    }

    public static function telegramInputs(): array
    {
        return [
            'bare handle' => ['PoloSupport', 'tg://resolve?domain=PoloSupport', '@PoloSupport'],
            'at handle' => ['@PoloSupport', 'tg://resolve?domain=PoloSupport', '@PoloSupport'],
            'public url' => ['https://t.me/PoloSupport', 'tg://resolve?domain=PoloSupport', '@PoloSupport'],
            'private invite' => ['https://t.me/+AbC123_xy', 'tg://join?invite=AbC123_xy', '私密邀请链接'],
        ];
    }
}
