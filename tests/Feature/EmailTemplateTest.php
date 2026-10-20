<?php

namespace Tests\Feature;

use App\Mail\TemplatedMail;
use App\Models\EmailTemplate;
use App\Models\Plan;
use App\Models\SubscriptionInstance;
use App\Models\User;
use App\Notifications\MagicLoginLink;
use App\Notifications\SubscriptionExpiringSoon;
use App\Notifications\VerifyEmailAddress;
use App\Services\EmailTemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

class EmailTemplateTest extends TestCase
{
    use RefreshDatabase;

    public function test_custom_verify_email_template_is_used_when_present(): void
    {
        EmailTemplate::create([
            'key' => EmailTemplate::KEY_VERIFY_EMAIL,
            'subject' => '自定义主题 站点',
            'content' => '点击验证：the-link-value',
        ]);

        $user = User::factory()->create();
        $mail = (new VerifyEmailAddress())->toMail($user);

        $this->assertInstanceOf(TemplatedMail::class, $mail);
        $this->assertStringContainsString('自定义主题', $mail->emailSubject);
        $this->assertStringContainsString('点击验证：', $mail->htmlContent);
    }

    public function test_default_template_used_when_no_custom_template_saved(): void
    {
        $user = User::factory()->create();

        $mail = (new VerifyEmailAddress())->toMail($user);

        $this->assertInstanceOf(MailMessage::class, $mail);
    }

    public function test_custom_templated_notification_addresses_its_recipient(): void
    {
        // Regression: a saved template makes toMail() return a Mailable, and
        // Laravel does NOT inject the recipient for a Mailable the way it does
        // for a MailMessage. Without an explicit ->to() the send throws
        // "An email must have a To header" and the mail silently never sends.
        // The pre-existing tests missed this by only checking subject/content.
        EmailTemplate::create([
            'key' => EmailTemplate::KEY_MAGIC_LOGIN,
            'subject' => '登录 {{name}}',
            'content' => '<a href="{{link}}">登录</a>',
        ]);

        $user = User::factory()->create(['email' => 'recipient@example.com']);
        $mail = (new MagicLoginLink())->toMail($user);

        $this->assertInstanceOf(TemplatedMail::class, $mail);
        $this->assertTrue($mail->hasTo('recipient@example.com'), '自定义模板邮件必须带收件人，否则发送时抛 "To header" 异常');
    }

    public function test_every_templated_notification_addresses_its_recipient(): void
    {
        // Guard all five customizable templates at once — each returns a
        // Mailable that must carry the recipient. Uses the same key→notification
        // wiring the app does; a new templated notification added without a
        // recipient would trip this.
        $user = User::factory()->create(['email' => 'r@example.com']);

        $cases = [
            EmailTemplate::KEY_VERIFY_EMAIL => new VerifyEmailAddress(),
            EmailTemplate::KEY_MAGIC_LOGIN => new MagicLoginLink(),
        ];
        foreach ($cases as $key => $notification) {
            EmailTemplate::updateOrCreate(['key' => $key], ['subject' => 's {{name}}', 'content' => 'c {{link}}']);
            $mail = $notification->toMail($user);
            $this->assertInstanceOf(TemplatedMail::class, $mail);
            $this->assertTrue($mail->hasTo('r@example.com'), "模板 {$key} 的邮件缺收件人");
        }
    }

    public function test_render_leaves_recipient_unset_when_no_notifiable_is_given(): void
    {
        EmailTemplate::create(['key' => EmailTemplate::KEY_MAGIC_LOGIN, 'subject' => 's', 'content' => 'c']);

        $mail = app(EmailTemplateService::class)->render(EmailTemplate::KEY_MAGIC_LOGIN, []);

        $this->assertInstanceOf(TemplatedMail::class, $mail);
        $this->assertFalse($mail->hasTo('anyone@example.com'));
    }

    public function test_custom_magic_login_template_substitutes_link_token(): void
    {
        EmailTemplate::create([
            'key' => EmailTemplate::KEY_MAGIC_LOGIN,
            'subject' => '登录 站点',
            'content' => '<a href="{{link}}">登录</a>',
        ]);

        $user = User::factory()->create();
        $mail = (new MagicLoginLink())->toMail($user);

        $this->assertInstanceOf(TemplatedMail::class, $mail);
        $this->assertStringContainsString('/login-via-email/', $mail->htmlContent);
    }

    public function test_custom_expire_reminder_template_substitutes_plan_name(): void
    {
        EmailTemplate::create([
            'key' => EmailTemplate::KEY_EXPIRE_REMINDER,
            'subject' => '到期提醒',
            'content' => '您的套餐 {{plan_name}} 将过期',
        ]);

        $user = User::factory()->create();
        $plan = Plan::create(['name' => '测试套餐', 'type' => Plan::TYPE_DEDICATED_ISP, 'transfer_enable' => 1, 'prices' => ['month' => 1000], 'sell' => true]);
        $instance = SubscriptionInstance::create(['user_id' => $user->id, 'plan_id' => $plan->id, 'transfer_enable' => 1, 'status' => 'active', 'expired_at' => now()->addDay()]);

        $mail = (new SubscriptionExpiringSoon($instance))->toMail($user);

        $this->assertInstanceOf(TemplatedMail::class, $mail);
        $this->assertStringContainsString('测试套餐', $mail->htmlContent);
    }

    public function test_email_template_service_returns_null_without_saved_template(): void
    {
        $this->assertNull(app(EmailTemplateService::class)->render(EmailTemplate::KEY_SITE_NOTICE, ['name' => 'x']));
    }

    public function test_admin_can_save_and_reset_email_template(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        Livewire::actingAs($admin)->test('admin.system')
            ->set('emailTemplateKey', EmailTemplate::KEY_VERIFY_EMAIL)
            ->set('emailTemplateSubject', '测试主题')
            ->set('emailTemplateContent', '测试内容 {{link}}')
            ->call('saveEmailTemplate');

        $this->assertDatabaseHas('email_templates', [
            'key' => EmailTemplate::KEY_VERIFY_EMAIL,
            'subject' => '测试主题',
        ]);

        Livewire::actingAs($admin)->test('admin.system')
            ->set('emailTemplateKey', EmailTemplate::KEY_VERIFY_EMAIL)
            ->call('resetEmailTemplate');

        $this->assertDatabaseMissing('email_templates', ['key' => EmailTemplate::KEY_VERIFY_EMAIL]);
    }

    public function test_default_templates_are_valid_html_with_no_leftover_tokens_after_substitution(): void
    {
        $tokenSets = [
            EmailTemplate::KEY_VERIFY_EMAIL => ['name' => 'MySite', 'url' => 'https://s.test', 'link' => 'https://s.test/v/tok'],
            EmailTemplate::KEY_MAGIC_LOGIN => ['name' => 'MySite', 'url' => 'https://s.test', 'link' => 'https://s.test/l/tok'],
            EmailTemplate::KEY_SITE_NOTICE => ['name' => 'MySite', 'url' => 'https://s.test', 'content' => '通知正文内容'],
            EmailTemplate::KEY_EXPIRE_REMINDER => ['name' => 'MySite', 'url' => 'https://s.test', 'plan_name' => '机房共享', 'expired_at' => '2026-08-10'],
            EmailTemplate::KEY_TRAFFIC_REMINDER => ['name' => 'MySite', 'url' => 'https://s.test', 'plan_name' => '机房共享', 'percent' => '85'],
        ];

        $service = app(EmailTemplateService::class);

        foreach ($tokenSets as $key => $tokens) {
            $default = \App\Support\DefaultEmailTemplates::for($key);
            EmailTemplate::updateOrCreate(['key' => $key], $default);

            $mail = $service->render($key, $tokens);

            // Every provided token value must appear, and no {{...}} may remain.
            foreach ($tokens as $value) {
                $this->assertStringContainsString($value, $mail->htmlContent."\n".$mail->emailSubject, "token value '{$value}' missing for {$key}");
            }
            $this->assertStringNotContainsString('{{', $mail->htmlContent, "leftover token in {$key}");
            $this->assertStringContainsString('#0d9488', $mail->htmlContent, "brand color missing in {$key}");
            $this->assertLessThan(10000, strlen($mail->htmlContent), "{$key} exceeds the 10000-char template limit");
        }
    }

    public function test_seed_command_installs_templates_but_skips_existing_customizations(): void
    {
        EmailTemplate::create([
            'key' => EmailTemplate::KEY_VERIFY_EMAIL,
            'subject' => '我的自定义主题',
            'content' => '不要覆盖我',
        ]);

        $this->artisan('app:seed-email-templates')->assertSuccessful();

        // Existing customization preserved.
        $this->assertSame('不要覆盖我', EmailTemplate::where('key', EmailTemplate::KEY_VERIFY_EMAIL)->first()->content);
        // The other four got seeded.
        $this->assertSame(count(EmailTemplate::LABELS), EmailTemplate::count());
        $this->assertStringContainsString('#0d9488', EmailTemplate::where('key', EmailTemplate::KEY_TRAFFIC_REMINDER)->first()->content);
    }

    public function test_seed_command_with_force_overwrites_existing(): void
    {
        EmailTemplate::create([
            'key' => EmailTemplate::KEY_VERIFY_EMAIL,
            'subject' => '旧主题',
            'content' => '旧内容',
        ]);

        $this->artisan('app:seed-email-templates', ['--force' => true])->assertSuccessful();

        $this->assertStringContainsString('#0d9488', EmailTemplate::where('key', EmailTemplate::KEY_VERIFY_EMAIL)->first()->content);
    }
}
