<?php

use App\Models\AppClient;
use App\Models\EmailTemplate;
use App\Models\Setting;
use App\Models\SubscribeTemplate;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts.admin', ['title' => '系统配置'])] class extends Component
{
    use WithFileUploads;

    public string $site_name = '';
    public string $site_description = '';
    public string $site_url = '';
    public bool $force_https = false;
    public string $site_logo_url = '';
    public string $subscribe_url = '';
    public string $tos_url = '';
    public bool $stop_register = false;

    public bool $login_attempt_limit_enabled = true;
    public string $login_max_attempts = '5';
    public string $login_lockout_minutes = '60';

    public string $admin_path = '';
    public bool $email_whitelist_enabled = false;
    public string $email_whitelist = '';
    public bool $captcha_enabled = false;
    public bool $ip_register_limit_enabled = false;
    public string $ip_register_max_attempts = '5';
    public string $ip_register_lockout_minutes = '60';
    public bool $email_verification_enabled = false;
    public bool $block_gmail_alias = false;
    public bool $safe_mode_enabled = false;

    public string $commission_rate = '10';
    public string $commission_hold_days = '0';
    public string $max_invite_codes = '5';
    public bool $invite_code_never_expires = true;
    public bool $commission_first_payment_only = false;
    public bool $three_tier_commission_enabled = false;
    public string $commission_tier1_rate = '100';
    public string $commission_tier2_rate = '0';
    public string $commission_tier3_rate = '0';

    public string $telegram_group_url = '';
    public string $telegram_bot_token = '';
    public string $telegram_bot_username = '';

    public bool $support_widget_enabled = false;
    public string $support_telegram = '';
    public string $support_telegram_group = '';
    public string $support_email = '';
    public string $support_wechat = '';
    public string $support_qq = '';
    public string $support_note = '';
    // Stored relative paths on the public disk; the temp * _upload props hold a
    // freshly picked file until save.
    public string $support_wechat_qr = '';
    public string $support_qq_qr = '';
    public $support_wechat_qr_upload = null;
    public $support_qq_qr_upload = null;

    public array $appClients = [];
    public array $appClientNotes = [];
    public array $appClientApprovalHashes = [];
    public string $newAppClientPlatform = 'windows';
    public string $newAppClientName = '';
    public string $newAppClientNote = '';
    public string $newAppClientUrl = '';
    public string $newAppClientSourceType = 'manual';
    public string $newAppClientGithubRepo = '';
    public string $newAppClientAssetPattern = '';

    public string $node_comm_key = '';
    public string $node_pull_interval = '60';
    public string $node_push_interval = '60';

    public string $provisioner_driver = 'onip';
    public string $onip_app_id = '';
    public string $onip_app_secret = '';
    public string $onip_base_url = 'https://api-ip.onip.cc';
    public string $onip_callback_secret = '';


    public string $app_download_note = '';

    // Registry of real static-IP provisioning providers, keyed by their
    // provisioner_driver value. To add another ISP API vendor: (1) add an
    // entry here, (2) create its XxxProvisioner (implements
    // ProvisionerInterface), (3) add a case to AppServiceProvider's binding.
    // The 开通方式 field then picks it up automatically — a single provider
    // renders as a fixed label, two or more render as a dropdown.
    public array $provisionDrivers = [
        'onip' => 'onip.cc 真实静态代理',
    ];

    public bool $mail_smtp_configured = false;
    public string $mail_host = '';
    public string $mail_port = '587';
    public string $mail_encryption = 'tls';
    public string $mail_username = '';
    public string $mail_password = '';
    public string $mail_from_address = '';
    public bool $mail_reminders_enabled = true;

    public string $mailSubTab = 'basic';
    public string $emailTemplateKey = 'verify_email';
    public string $emailTemplateSubject = '';
    public string $emailTemplateContent = '';

    public string $subscribeTemplateClient = 'singbox';
    public string $subscribeTemplateContent = '';

    public bool $subscribe_allow_plan_change = true;
    public bool $plan_change_proration_enabled = false;
    public string $subscribe_path = 'subscribe';
    public bool $subscribe_show_userinfo = true;
    public bool $subscribe_show_protocol_name = false;

    public string $successMessage = '';
    public string $errorMessage = '';

    public function mount(): void
    {
        $this->site_name = Setting::get('site_name', config('app.name'));
        $this->site_description = Setting::get('site_description', '');
        $this->site_url = Setting::get('site_url', '');
        $this->force_https = Setting::getBool('force_https');
        $this->site_logo_url = Setting::get('site_logo_url', '');
        $this->subscribe_url = Setting::get('subscribe_url', '');
        $this->tos_url = Setting::get('tos_url', '');
        $this->stop_register = Setting::getBool('stop_register');
        $this->login_attempt_limit_enabled = Setting::getBool('login_attempt_limit_enabled', true);
        $this->login_max_attempts = (string) Setting::get('login_max_attempts', 5);
        $this->login_lockout_minutes = (string) Setting::get('login_lockout_minutes', 60);
        $this->admin_path = Setting::get('admin_path', config('admin.path'));
        $this->email_whitelist_enabled = Setting::getBool('email_whitelist_enabled', false);
        $this->email_whitelist = Setting::get('email_whitelist', '');
        $this->captcha_enabled = Setting::getBool('captcha_enabled', false);
        $this->ip_register_limit_enabled = Setting::getBool('ip_register_limit_enabled', false);
        $this->ip_register_max_attempts = (string) Setting::get('ip_register_max_attempts', 5);
        $this->ip_register_lockout_minutes = (string) Setting::get('ip_register_lockout_minutes', 60);
        $this->email_verification_enabled = Setting::getBool('email_verification_enabled', false);
        $this->block_gmail_alias = Setting::getBool('block_gmail_alias', false);
        $this->safe_mode_enabled = Setting::getBool('safe_mode_enabled', false);
        $this->commission_rate = (string) Setting::get('commission_rate', 10);
        $this->commission_hold_days = (string) Setting::get('commission_hold_days', 0);
        $this->max_invite_codes = (string) Setting::get('max_invite_codes', 5);
        $this->invite_code_never_expires = Setting::getBool('invite_code_never_expires', true);
        $this->commission_first_payment_only = Setting::getBool('commission_first_payment_only', false);
        $this->three_tier_commission_enabled = Setting::getBool('three_tier_commission_enabled', false);
        $this->commission_tier1_rate = (string) Setting::get('commission_tier1_rate', 100);
        $this->commission_tier2_rate = (string) Setting::get('commission_tier2_rate', 0);
        $this->commission_tier3_rate = (string) Setting::get('commission_tier3_rate', 0);
        $this->telegram_group_url = Setting::get('telegram_group_url', '');
        $this->telegram_bot_token = Setting::get('telegram_bot_token', '');
        $this->telegram_bot_username = Setting::get('telegram_bot_username', '');
        $this->support_widget_enabled = Setting::getBool('support_widget_enabled', false);
        $this->support_telegram = Setting::get('support_telegram', '');
        $this->support_telegram_group = Setting::get('support_telegram_group', '');
        $this->support_email = Setting::get('support_email', '');
        $this->support_wechat = Setting::get('support_wechat', '');
        $this->support_qq = Setting::get('support_qq', '');
        $this->support_note = Setting::get('support_note', '');
        $this->support_wechat_qr = Setting::get('support_wechat_qr', '');
        $this->support_qq_qr = Setting::get('support_qq_qr', '');
        $this->loadAppClients();
        $this->app_download_note = Setting::get('app_download_note', '');
        $this->mail_smtp_configured = Setting::getBool('mail_smtp_configured', false);
        $this->mail_host = Setting::get('mail_host', '');
        $this->mail_port = (string) Setting::get('mail_port', 587);
        $this->mail_encryption = Setting::get('mail_encryption', 'tls');
        $this->mail_username = Setting::get('mail_username', '');
        $this->mail_password = Setting::get('mail_password', '');
        $this->mail_from_address = Setting::get('mail_from_address', '');
        $this->mail_reminders_enabled = Setting::getBool('mail_reminders_enabled', true);
        $this->loadEmailTemplate();
        $this->loadSubscribeTemplate();

        $this->node_comm_key = Setting::get('node_comm_key', '');
        $this->node_pull_interval = (string) Setting::get('node_pull_interval', 60);
        $this->node_push_interval = (string) Setting::get('node_push_interval', 60);

        if ($this->node_comm_key === '') {
            $this->node_comm_key = Str::random(32);
            Setting::set('node_comm_key', $this->node_comm_key);
        }

        $this->provisioner_driver = Setting::get('provisioner_driver', 'onip');
        $this->onip_app_id = Setting::get('onip_app_id', '');
        $this->onip_app_secret = Setting::get('onip_app_secret', '');
        $this->onip_base_url = Setting::get('onip_base_url', 'https://api-ip.onip.cc');

        // The inbound onip callback is gated on this shared secret (baked into
        // the callback URL below). Auto-generate one so the endpoint is never
        // left open with an empty secret — same pattern as node_comm_key.
        $this->onip_callback_secret = Setting::get('onip_callback_secret', '');
        if ($this->onip_callback_secret === '') {
            $this->onip_callback_secret = Str::random(40);
            Setting::set('onip_callback_secret', $this->onip_callback_secret);
        }

        $this->subscribe_allow_plan_change = Setting::getBool('subscribe_allow_plan_change', true);
        $this->plan_change_proration_enabled = Setting::getBool('plan_change_proration_enabled', false);
        $this->subscribe_path = Setting::get('subscribe_path', 'subscribe') ?: 'subscribe';
        $this->subscribe_show_userinfo = Setting::getBool('subscribe_show_userinfo', true);
        $this->subscribe_show_protocol_name = Setting::getBool('subscribe_show_protocol_name', false);
    }

    public function save(): void
    {
        $this->validate([
            'site_name' => ['required', 'string', 'max:255'],
            'site_description' => ['nullable', 'string', 'max:500'],
            'site_url' => ['nullable', 'url'],
            'site_logo_url' => ['nullable', 'url'],
            'subscribe_url' => ['nullable', 'url'],
            'tos_url' => ['nullable', 'url'],
        ]);

        Setting::set('site_name', $this->site_name);
        Setting::set('site_description', $this->site_description);
        Setting::set('site_url', $this->site_url);
        Setting::set('force_https', $this->force_https);
        Setting::set('site_logo_url', rtrim($this->site_logo_url, '/'));
        Setting::set('subscribe_url', rtrim($this->subscribe_url, '/'));
        Setting::set('tos_url', $this->tos_url);
        Setting::set('stop_register', $this->stop_register);

        $this->successMessage = '设置已保存';
    }

    public function saveSecurity(): void
    {
        $this->validate([
            'login_max_attempts' => ['required', 'integer', 'min:1', 'max:100'],
            'login_lockout_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'admin_path' => ['required', 'string', 'regex:/^[a-zA-Z0-9_-]+$/', 'max:64'],
            'email_whitelist' => ['nullable', 'string', 'max:2000'],
            'ip_register_max_attempts' => ['required', 'integer', 'min:1', 'max:100'],
            'ip_register_lockout_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
        ]);

        Setting::set('login_attempt_limit_enabled', $this->login_attempt_limit_enabled);
        Setting::set('login_max_attempts', (int) $this->login_max_attempts);
        Setting::set('login_lockout_minutes', (int) $this->login_lockout_minutes);
        Setting::set('admin_path', $this->admin_path);
        Setting::set('email_whitelist_enabled', $this->email_whitelist_enabled);
        Setting::set('email_whitelist', $this->email_whitelist);
        Setting::set('captcha_enabled', $this->captcha_enabled);
        Setting::set('ip_register_limit_enabled', $this->ip_register_limit_enabled);
        Setting::set('ip_register_max_attempts', (int) $this->ip_register_max_attempts);
        Setting::set('ip_register_lockout_minutes', (int) $this->ip_register_lockout_minutes);
        Setting::set('email_verification_enabled', $this->email_verification_enabled);
        Setting::set('block_gmail_alias', $this->block_gmail_alias);
        Setting::set('safe_mode_enabled', $this->safe_mode_enabled);

        $this->successMessage = '安全设置已保存，后台路径变更需要重启服务才能生效';
    }

    public function saveCommission(): void
    {
        $this->validate([
            'commission_rate' => ['required', 'integer', 'min:0', 'max:100'],
            'commission_hold_days' => ['required', 'integer', 'min:0', 'max:365'],
            'max_invite_codes' => ['required', 'integer', 'min:1', 'max:100'],
            'commission_tier1_rate' => ['required', 'integer', 'min:0', 'max:100'],
            'commission_tier2_rate' => ['required', 'integer', 'min:0', 'max:100'],
            'commission_tier3_rate' => ['required', 'integer', 'min:0', 'max:100'],
        ]);

        if ($this->three_tier_commission_enabled
            && ((int) $this->commission_tier1_rate + (int) $this->commission_tier2_rate + (int) $this->commission_tier3_rate) > 100) {
            $this->addError('commission_tier1_rate', '三级分成比例合计不能大于100%');

            return;
        }

        Setting::set('commission_rate', (int) $this->commission_rate);
        Setting::set('commission_hold_days', (int) $this->commission_hold_days);
        Setting::set('max_invite_codes', (int) $this->max_invite_codes);
        Setting::set('invite_code_never_expires', $this->invite_code_never_expires);
        Setting::set('commission_first_payment_only', $this->commission_first_payment_only);
        Setting::set('three_tier_commission_enabled', $this->three_tier_commission_enabled);
        Setting::set('commission_tier1_rate', (int) $this->commission_tier1_rate);
        Setting::set('commission_tier2_rate', (int) $this->commission_tier2_rate);
        Setting::set('commission_tier3_rate', (int) $this->commission_tier3_rate);

        $this->successMessage = '邀请佣金设置已保存';
    }

    public function saveTelegram(): void
    {
        $this->validate([
            'telegram_group_url' => ['nullable', 'url'],
            'telegram_bot_token' => ['nullable', 'string'],
            'telegram_bot_username' => ['nullable', 'string'],
        ]);

        Setting::set('telegram_group_url', $this->telegram_group_url);
        Setting::set('telegram_bot_token', $this->telegram_bot_token);
        Setting::set('telegram_bot_username', ltrim($this->telegram_bot_username, '@'));

        $this->successMessage = 'Telegram 设置已保存';
    }

    public function saveSupport(): void
    {
        $this->validate([
            'support_email' => ['nullable', 'email'],
            'support_telegram' => ['nullable', 'string', 'max:255'],
            'support_telegram_group' => ['nullable', 'string', 'max:255'],
            'support_wechat' => ['nullable', 'string', 'max:255'],
            'support_qq' => ['nullable', 'string', 'max:255'],
            'support_note' => ['nullable', 'string', 'max:500'],
            'support_wechat_qr_upload' => ['nullable', 'image', 'max:4096'],
            'support_qq_qr_upload' => ['nullable', 'image', 'max:4096'],
        ]);

        // A newly picked QR replaces the old file (which we then delete so we
        // don't leak orphaned uploads).
        if ($this->support_wechat_qr_upload) {
            $this->support_wechat_qr = $this->storeQr($this->support_wechat_qr_upload, $this->support_wechat_qr);
            $this->support_wechat_qr_upload = null;
        }
        if ($this->support_qq_qr_upload) {
            $this->support_qq_qr = $this->storeQr($this->support_qq_qr_upload, $this->support_qq_qr);
            $this->support_qq_qr_upload = null;
        }

        Setting::set('support_widget_enabled', $this->support_widget_enabled);
        Setting::set('support_telegram', trim($this->support_telegram));
        Setting::set('support_telegram_group', trim($this->support_telegram_group));
        Setting::set('support_email', trim($this->support_email));
        Setting::set('support_wechat', trim($this->support_wechat));
        Setting::set('support_qq', trim($this->support_qq));
        Setting::set('support_note', trim($this->support_note));
        Setting::set('support_wechat_qr', $this->support_wechat_qr);
        Setting::set('support_qq_qr', $this->support_qq_qr);

        $this->successMessage = '客服设置已保存';
    }

    private function storeQr($file, string $oldPath): string
    {
        $path = $file->store('support', 'public');

        if ($oldPath !== '' && $oldPath !== $path) {
            \Illuminate\Support\Facades\Storage::disk('public')->delete($oldPath);
        }

        return $path;
    }

    public function removeWechatQr(): void
    {
        if ($this->support_wechat_qr !== '') {
            \Illuminate\Support\Facades\Storage::disk('public')->delete($this->support_wechat_qr);
        }
        $this->support_wechat_qr = '';
        $this->support_wechat_qr_upload = null;
        Setting::set('support_wechat_qr', '');
        $this->successMessage = '微信二维码已移除';
    }

    public function removeQqQr(): void
    {
        if ($this->support_qq_qr !== '') {
            \Illuminate\Support\Facades\Storage::disk('public')->delete($this->support_qq_qr);
        }
        $this->support_qq_qr = '';
        $this->support_qq_qr_upload = null;
        Setting::set('support_qq_qr', '');
        $this->successMessage = 'QQ二维码已移除';
    }

    /**
     * These aren't a first-party app this panel ships — they're
     * recommended third-party open-source proxy clients (Clash Verge,
     * Shadowrocket, ...), so each platform can list several, each with
     * just a name and a link — no version number to track since the admin
     * doesn't control those projects' release cadence.
     */
    public function loadAppClients(): void
    {
        $this->appClients = AppClient::orderBy('platform')->orderBy('sort')->orderBy('id')->get()->groupBy('platform')->all();
        $this->appClientNotes = AppClient::pluck('note', 'id')->map(fn ($n) => (string) ($n ?? ''))->all();
    }

    public function addAppClient(): void
    {
        $isGithub = $this->newAppClientSourceType === \App\Models\AppClient::SOURCE_GITHUB;

        $this->validate(array_merge([
            'newAppClientPlatform' => ['required', 'in:'.implode(',', array_keys(AppClient::PLATFORMS))],
            'newAppClientName' => ['required', 'string', 'max:255'],
        ], $isGithub ? [
            'newAppClientGithubRepo' => ['required', 'regex:/^[\w.-]+\/[\w.-]+$/'],
            'newAppClientAssetPattern' => [
                'required',
                'string',
                'max:255',
                fn (string $attribute, mixed $value, \Closure $fail) => @preg_match((string) $value, '') === false
                    ? $fail('文件名匹配规则不是有效的 PHP 正则。')
                    : null,
            ],
        ] : [
            'newAppClientUrl' => ['required', 'url'],
        ]));

        $client = AppClient::create([
            'platform' => $this->newAppClientPlatform,
            'name' => $this->newAppClientName,
            'note' => $this->newAppClientNote ?: null,
            'url' => $isGithub ? '' : $this->newAppClientUrl,
            'source_type' => $this->newAppClientSourceType,
            'github_repo' => $isGithub ? trim($this->newAppClientGithubRepo) : null,
            'asset_pattern' => $isGithub ? trim($this->newAppClientAssetPattern) : null,
        ]);

        if ($isGithub) {
            \App\Jobs\SyncAppClientFromGithub::dispatch($client->id);
        }

        $this->newAppClientName = '';
        $this->newAppClientNote = '';
        $this->newAppClientUrl = '';
        $this->newAppClientGithubRepo = '';
        $this->newAppClientAssetPattern = '';
        $this->loadAppClients();
        $this->successMessage = $isGithub ? '客户端已添加，新版本将下载到私有隔离区等待审核' : '客户端已添加';
    }

    public function syncAppClientNow(int $id): void
    {
        $client = AppClient::where('source_type', AppClient::SOURCE_GITHUB)->find($id);

        if (! $client) {
            $this->errorMessage = '该客户端不是 GitHub 自动同步来源';

            return;
        }

        \App\Jobs\SyncAppClientFromGithub::dispatch($client->id);
        $this->successMessage = '已开始检查，新版本只会进入私有隔离区';
    }

    public function approveAppClientRelease(int $id, \App\Services\AppClientArtifactService $artifacts): void
    {
        $field = "appClientApprovalHashes.{$id}";
        $this->validateOnly($field, [
            $field => ['required', 'regex:/\A[a-fA-F0-9]{64}\z/'],
        ], [
            $field.'.required' => '请输入从可信渠道取得的 SHA-256。',
            $field.'.regex' => 'SHA-256 必须是 64 位十六进制。',
        ]);

        $client = AppClient::find($id);

        if (! $client) {
            $this->errorMessage = '客户端不存在';

            return;
        }

        try {
            $artifacts->approve($client, $this->appClientApprovalHashes[$id]);
        } catch (\RuntimeException $exception) {
            $this->addError($field, $exception->getMessage());

            return;
        }

        unset($this->appClientApprovalHashes[$id]);
        $this->loadAppClients();
        $this->successMessage = '哈希核验通过，新客户端版本已发布';
    }

    public function verifyPublishedAppClient(int $id, \App\Services\AppClientArtifactService $artifacts): void
    {
        $field = "appClientApprovalHashes.{$id}";
        $this->validateOnly($field, [
            $field => ['required', 'regex:/\A[a-fA-F0-9]{64}\z/'],
        ]);

        $client = AppClient::find($id);

        if (! $client) {
            $this->errorMessage = '客户端不存在';

            return;
        }

        try {
            $artifacts->verifyPublished($client, $this->appClientApprovalHashes[$id]);
        } catch (\RuntimeException $exception) {
            $this->addError($field, $exception->getMessage());

            return;
        }

        unset($this->appClientApprovalHashes[$id]);
        $this->loadAppClients();
        $this->successMessage = '当前客户端文件已建立可信哈希基线';
    }

    public function rejectAppClientRelease(int $id, \App\Services\AppClientArtifactService $artifacts): void
    {
        $client = AppClient::find($id);

        if ($client) {
            $artifacts->reject($client);
        }

        unset($this->appClientApprovalHashes[$id]);
        $this->loadAppClients();
        $this->successMessage = '候选版本已从隔离区删除';
    }

    /**
     * Force an immediate re-sync of every auto-sync (GitHub) client at once —
     * same jobs the daily app:sync-clients schedule dispatches, just on demand
     * so an admin doesn't have to click 立即同步 on each one.
     */
    public function saveAppDownloadNote(): void
    {
        Setting::set('app_download_note', $this->app_download_note);
        $this->successMessage = '下载说明已保存';
    }

    public function saveAppClientNote(int $id): void
    {
        $note = trim($this->appClientNotes[$id] ?? '');
        AppClient::where('id', $id)->update(['note' => $note !== '' ? $note : null]);
        $this->loadAppClients();
        $this->successMessage = '客户端说明已保存';
    }

    public function syncAllAppClients(): void
    {
        $ids = \App\Models\AppClient::where('source_type', \App\Models\AppClient::SOURCE_GITHUB)->pluck('id');

        foreach ($ids as $id) {
            \App\Jobs\SyncAppClientFromGithub::dispatch($id);
        }

        $this->successMessage = $ids->isEmpty()
            ? '没有需要同步的自动同步客户端'
            : "已开始同步 {$ids->count()} 个自动同步客户端，稍后刷新页面查看结果";
    }

    public function removeAppClient(int $id): void
    {
        $client = AppClient::find($id);
        if ($client?->local_path) {
            \Illuminate\Support\Facades\Storage::disk('public')->delete($client->local_path);
        }
        if ($client?->pending_path) {
            \Illuminate\Support\Facades\Storage::disk('local')->delete($client->pending_path);
        }
        AppClient::where('id', $id)->delete();
        $this->loadAppClients();
    }

    public function saveMail(): void
    {
        $this->validate([
            'mail_host' => ['required', 'string', 'max:255'],
            'mail_port' => ['required', 'integer', 'min:1', 'max:65535'],
            'mail_encryption' => ['required', 'in:none,tls,ssl'],
            'mail_username' => ['required', 'string', 'max:255'],
            'mail_password' => ['required', 'string', 'max:255'],
            'mail_from_address' => ['required', 'email'],
        ]);

        Setting::set('mail_smtp_configured', true);
        Setting::set('mail_host', $this->mail_host);
        Setting::set('mail_port', (int) $this->mail_port);
        Setting::set('mail_encryption', $this->mail_encryption);
        Setting::set('mail_username', $this->mail_username);
        Setting::set('mail_password', $this->mail_password);
        Setting::set('mail_from_address', $this->mail_from_address);
        Setting::set('mail_reminders_enabled', $this->mail_reminders_enabled);

        $this->successMessage = '邮件设置已保存，SMTP 配置立即生效（无需重启）';
    }

    /**
     * Send a one-off test email to the logged-in admin using whatever SMTP
     * values are currently typed in the form (applied at runtime, so the admin
     * can verify credentials before committing them). Failures surface on the
     * red error channel with the SMTP error, not a misleading green banner.
     */
    public function sendTestMail(): void
    {
        $this->successMessage = '';
        $this->errorMessage = '';

        $this->validate([
            'mail_host' => ['required', 'string', 'max:255'],
            'mail_port' => ['required', 'integer', 'min:1', 'max:65535'],
            'mail_encryption' => ['required', 'in:none,tls,ssl'],
            'mail_username' => ['required', 'string', 'max:255'],
            'mail_password' => ['required', 'string', 'max:255'],
            'mail_from_address' => ['required', 'email'],
        ]);

        // Apply the typed values to the mailer for this request only (mirrors
        // the boot-time override in AppServiceProvider, same scheme mapping).
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.scheme' => $this->mail_encryption === 'ssl' ? 'smtps' : null,
            'mail.mailers.smtp.host' => $this->mail_host,
            'mail.mailers.smtp.port' => (int) $this->mail_port,
            'mail.mailers.smtp.username' => $this->mail_username,
            'mail.mailers.smtp.password' => $this->mail_password,
            'mail.from.address' => $this->mail_from_address ?: config('mail.from.address'),
            'mail.from.name' => Setting::get('site_name', config('mail.from.name')),
        ]);

        $to = auth()->user()->email;

        try {
            \Illuminate\Support\Facades\Mail::raw('这是一封来自面板的 SMTP 测试邮件，能收到就说明邮件配置正常。', function ($message) use ($to) {
                $message->to($to)->subject('面板 · SMTP 测试邮件');
            });
            $this->successMessage = "测试邮件已发送到 {$to}，请查收（记得也看下垃圾箱）。";
        } catch (\Throwable $e) {
            $this->errorMessage = '发送失败：'.$e->getMessage();
        }
    }

    /**
     * Sensible starting content for each template key when nothing custom
     * has been saved yet — mirrors the hardcoded copy each Notification
     * class falls back to, so the editor never opens empty.
     */
    private function defaultEmailTemplate(string $key): array
    {
        return \App\Support\DefaultEmailTemplates::for($key);
    }

    public function updatedEmailTemplateKey(): void
    {
        $this->loadEmailTemplate();
    }

    public function loadEmailTemplate(): void
    {
        $template = EmailTemplate::where('key', $this->emailTemplateKey)->first();
        $defaults = $this->defaultEmailTemplate($this->emailTemplateKey);

        $this->emailTemplateSubject = $template->subject ?? $defaults['subject'];
        $this->emailTemplateContent = $template->content ?? $defaults['content'];
    }

    public function saveEmailTemplate(): void
    {
        $this->validate([
            'emailTemplateSubject' => ['required', 'string', 'max:255'],
            'emailTemplateContent' => ['required', 'string', 'max:10000'],
        ]);

        EmailTemplate::updateOrCreate(
            ['key' => $this->emailTemplateKey],
            ['subject' => $this->emailTemplateSubject, 'content' => $this->emailTemplateContent],
        );

        $this->successMessage = '模板已保存';
    }

    public function resetEmailTemplate(): void
    {
        EmailTemplate::where('key', $this->emailTemplateKey)->delete();
        $this->loadEmailTemplate();

        $this->successMessage = '已恢复为默认模板';
    }

    private function defaultSubscribeTemplate(string $client): string
    {
        return \App\Support\DefaultSubscribeTemplates::for($client);
    }

    public function updatedSubscribeTemplateClient(): void
    {
        $this->loadSubscribeTemplate();
    }

    public function loadSubscribeTemplate(): void
    {
        $template = SubscribeTemplate::where('client', $this->subscribeTemplateClient)->first();
        $this->subscribeTemplateContent = ($template && trim($template->content) !== '')
            ? $template->content
            : $this->defaultSubscribeTemplate($this->subscribeTemplateClient);
    }

    public function saveSubscribeTemplate(): void
    {
        $this->validate(['subscribeTemplateContent' => ['required', 'string', 'max:20000']]);

        SubscribeTemplate::updateOrCreate(
            ['client' => $this->subscribeTemplateClient],
            ['content' => $this->subscribeTemplateContent],
        );

        $this->successMessage = '订阅模板已保存';
    }

    public function resetSubscribeTemplate(): void
    {
        SubscribeTemplate::where('client', $this->subscribeTemplateClient)->delete();
        $this->loadSubscribeTemplate();

        $this->successMessage = '已恢复为默认模板';
    }

    public function saveNode(): void
    {
        $this->validate([
            'node_comm_key' => ['required', 'string', 'min:16', 'max:255'],
            'node_pull_interval' => ['required', 'integer', 'min:5', 'max:3600'],
            'node_push_interval' => ['required', 'integer', 'min:5', 'max:3600'],
        ]);

        Setting::set('node_comm_key', $this->node_comm_key);
        Setting::set('node_pull_interval', (int) $this->node_pull_interval);
        Setting::set('node_push_interval', (int) $this->node_push_interval);

        $this->successMessage = '节点配置已保存';
    }

    public function regenerateNodeKey(): void
    {
        $this->node_comm_key = Str::random(32);
        Setting::set('node_comm_key', $this->node_comm_key);

        $this->successMessage = '通讯密钥已重新生成，记得同步更新节点端配置';
    }

    public function saveProvision(): void
    {
        $this->validate([
            'provisioner_driver' => ['required', 'in:' . implode(',', array_keys($this->provisionDrivers))],
            'onip_app_id' => ['nullable', 'string', 'max:255'],
            'onip_app_secret' => ['nullable', 'string', 'max:255'],
            'onip_base_url' => ['nullable', 'url'],
        ]);

        Setting::set('provisioner_driver', $this->provisioner_driver);
        Setting::set('onip_app_id', $this->onip_app_id);
        Setting::set('onip_app_secret', $this->onip_app_secret);
        Setting::set('onip_base_url', rtrim($this->onip_base_url ?: 'https://api-ip.onip.cc', '/'));

        $this->successMessage = '开通渠道配置已保存';
    }

    /**
     * Read-only connectivity/signature check — GET /account has no side
     * effects (no order, no balance spent), so this is safe to call for
     * real right from the admin UI to verify AppId/AppSecret before
     * flipping provisioner_driver to onip.
     */
    public function testOnipConnection(): void
    {
        $this->successMessage = '';
        $this->errorMessage = '';

        // Persist first so the test hits whatever's currently typed in the
        // form, not a stale saved value.
        Setting::set('onip_app_id', $this->onip_app_id);
        Setting::set('onip_app_secret', $this->onip_app_secret);
        Setting::set('onip_base_url', rtrim($this->onip_base_url ?: 'https://api-ip.onip.cc', '/'));

        try {
            $account = app(\App\Services\OnipProvisioningService::class)->getAccount();
            $this->successMessage = "连接成功：账户 {$account['username']}，余额 {$account['balance']} {$account['currency']}";
        } catch (\Throwable $e) {
            $this->errorMessage = '连接失败：'.$e->getMessage();
        }
    }

    public function saveSubscribe(): void
    {
        $this->validate([
            'subscribe_path' => ['required', 'string', 'regex:/^[a-zA-Z0-9_-]+$/', 'max:64'],
        ]);

        Setting::set('subscribe_allow_plan_change', $this->subscribe_allow_plan_change);
        Setting::set('plan_change_proration_enabled', $this->plan_change_proration_enabled);
        Setting::set('subscribe_path', $this->subscribe_path);
        Setting::set('subscribe_show_userinfo', $this->subscribe_show_userinfo);
        Setting::set('subscribe_show_protocol_name', $this->subscribe_show_protocol_name);

        $this->successMessage = '订阅设置已保存，订阅路径变更需要重启服务才能生效';
    }
};
?>

<div class="space-y-6" x-data="{ tab: 'site' }" x-init="$watch('tab', () => { $wire.set('successMessage', ''); $wire.set('errorMessage', ''); })">
    <div>
        <h1 class="text-xl font-semibold text-gray-900 dark:text-gray-100">系统设置</h1>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">管理系统核心配置，包括站点、安全、邀请佣金、Telegram 和支付等设置</p>
    </div>

    @if ($successMessage)
        <div class="text-sm text-green-700 dark:text-green-400 bg-green-50 dark:bg-green-500/10 border border-green-100 dark:border-green-500/20 rounded-lg p-3">{{ $successMessage }}</div>
    @endif
    @if ($errorMessage)
        <div class="text-sm text-red-600 dark:text-red-400 bg-red-50 dark:bg-red-500/10 border border-red-100 dark:border-red-500/20 rounded-lg p-3">{{ $errorMessage }}</div>
    @endif

    <div class="flex flex-col md:flex-row gap-6">
        <div class="md:w-56 shrink-0">
            <div class="border border-gray-200 dark:border-gray-800 rounded-xl p-2 bg-white dark:bg-gray-900 space-y-0.5">
                @php
                    $tabLink = fn (string $key, string $icon, string $label) => compact('key', 'icon', 'label');
                    $settingsTabs = [
                        $tabLink('site', 'sliders', '站点设置'),
                        $tabLink('security', 'shield', '安全设置'),
                        $tabLink('subscribe', 'route', '订阅设置'),
                        $tabLink('commission', 'invite', '邀请&佣金设置'),
                        $tabLink('node', 'server', '节点配置'),
                        $tabLink('provision', 'server', '静态IP开通渠道'),
                        $tabLink('mail', 'mail', '邮件设置'),
                        $tabLink('telegram', 'telegram', 'Telegram 设置'),
                        $tabLink('support', 'ticket', '客服设置'),
                        $tabLink('app', 'sliders', 'APP 设置'),
                        $tabLink('subscribe-template', 'route', '订阅模板'),
                    ];
                @endphp
                @foreach ($settingsTabs as $t)
                    <button
                        type="button"
                        @click="tab = '{{ $t['key'] }}'"
                        :class="tab === '{{ $t['key'] }}' ? 'bg-gray-100 dark:bg-gray-800 text-gray-900 dark:text-gray-100 font-medium' : 'text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-800/60'"
                        class="w-full flex items-center gap-2.5 px-3 py-2 rounded-lg text-sm text-left"
                    >
                        @include('partials.icon', ['name' => $t['icon']])
                        {{ $t['label'] }}
                    </button>
                @endforeach
            </div>
        </div>

        <div class="flex-1 min-w-0">
            <div x-show="tab === 'site'" x-cloak class="border border-gray-200 dark:border-gray-800 rounded-xl p-5 bg-white dark:bg-gray-900 space-y-4 max-w-xl">
                <div>
                    <h2 class="font-medium text-gray-900 dark:text-gray-100">站点设置</h2>
                    <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">配置站点基本信息，包括站点名称、描述、网址等核心设置。</p>
                </div>

                <div>
                    <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">站点名称</label>
                    <input type="text" wire:model="site_name" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
                    @error('site_name') <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p> @enderror
                    <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">用于显示需要站点名称的地方。</p>
                </div>

                <div>
                    <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">站点描述</label>
                    <input type="text" wire:model="site_description" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
                    @error('site_description') <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">站点网址</label>
                    <input type="text" wire:model="site_url" placeholder="https://example.com" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
                    @error('site_url') <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p> @enderror
                    <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">当前网站最新网址，将会在邮件等需要用于网址处体现。</p>
                </div>

                <div>
                    <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">LOGO</label>
                    <input type="text" wire:model="site_logo_url" placeholder="请输入 LOGO URL，末尾不要/" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
                    @error('site_logo_url') <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p> @enderror
                    <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">用于显示需要 LOGO 的地方，留空则显示站点名称文字。</p>
                </div>

                <div>
                    <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">订阅URL</label>
                    <input type="text" wire:model="subscribe_url" placeholder="https://sub.example.com" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
                    @error('subscribe_url') <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p> @enderror
                    <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">用于订阅所使用，留空则为站点URL。</p>
                </div>

                <div>
                    <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">用户条款(TOS)URL</label>
                    <input type="text" wire:model="tos_url" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
                    @error('tos_url') <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p> @enderror
                    <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">用于跳转到用户条款(TOS)。</p>
                </div>

                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" wire:model="force_https">
                    强制 HTTPS
                </label>

                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" wire:model="stop_register">
                    停止新用户注册
                </label>

                <div class="pt-2">
                    <button wire:click="save" class="text-sm bg-gray-900 dark:bg-gray-100 text-white dark:text-gray-900 rounded-lg px-4 py-2">保存</button>
                </div>
            </div>

            <div x-show="tab === 'security'" x-cloak class="border border-gray-200 dark:border-gray-800 rounded-xl p-5 bg-white dark:bg-gray-900 space-y-4 max-w-xl">
                <div>
                    <h2 class="font-medium text-gray-900 dark:text-gray-100">安全设置</h2>
                    <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">配置系统安全相关选项，包括登录验证、注册限制等安全设置。</p>
                </div>

                <div>
                    <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">后台路径</label>
                    <input type="text" wire:model="admin_path" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm font-mono">
                    @error('admin_path') <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p> @enderror
                    <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">后台管理路径，修改后将会改变原有的 admin 路径。修改后需要重启服务才能生效。</p>
                </div>

                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" wire:model="email_verification_enabled">
                    邮箱验证
                </label>
                <p class="text-xs text-gray-400 dark:text-gray-500 -mt-3 ml-6">开启后将会强制要求用户进行邮箱验证。</p>

                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" wire:model="block_gmail_alias">
                    禁止使用Gmail多别名
                </label>
                <p class="text-xs text-gray-400 dark:text-gray-500 -mt-3 ml-6">开启后 someone+tag@gmail.com 这类别名将无法注册（会被归并到同一个真实邮箱判重）。</p>

                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" wire:model="safe_mode_enabled">
                    安全模式
                </label>
                <p class="text-xs text-gray-400 dark:text-gray-500 -mt-3 ml-6">开启后除了站点URL绑定的域名以外的访问都将会被403。</p>

                <div>
                    <label class="flex items-center gap-2 text-sm">
                        <input type="checkbox" wire:model="email_whitelist_enabled">
                        邮箱后缀白名单
                    </label>
                    <p class="text-xs text-gray-400 dark:text-gray-500 mt-1 ml-6">开启后在名单中的邮箱后缀才允许进行注册。</p>
                    <input type="text" wire:model="email_whitelist" placeholder="gmail.com,outlook.com（多个用逗号分隔）" class="mt-2 w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
                    @error('email_whitelist') <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p> @enderror
                </div>

                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" wire:model="captcha_enabled">
                    启用验证码
                </label>
                <p class="text-xs text-gray-400 dark:text-gray-500 -mt-3 ml-6">开启后用户注册时需要通过算术验证码验证。</p>

                <div>
                    <label class="flex items-center gap-2 text-sm">
                        <input type="checkbox" wire:model="ip_register_limit_enabled">
                        IP注册限制
                    </label>
                    <p class="text-xs text-gray-400 dark:text-gray-500 mt-1 ml-6">开启后将限制同一IP的注册次数。</p>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">注册次数</label>
                        <input type="number" wire:model="ip_register_max_attempts" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
                        @error('ip_register_max_attempts') <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p> @enderror
                        <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">同一IP允许的最大注册次数。</p>
                    </div>
                    <div>
                        <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">限制时长（分钟）</label>
                        <input type="number" wire:model="ip_register_lockout_minutes" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
                        @error('ip_register_lockout_minutes') <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p> @enderror
                        <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">注册限制的持续时间（分钟）。</p>
                    </div>
                </div>

                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" wire:model="login_attempt_limit_enabled">
                    密码尝试限制
                </label>
                <p class="text-xs text-gray-400 dark:text-gray-500 -mt-3 ml-6">开启后将限制密码尝试次数。</p>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">尝试次数</label>
                        <input type="number" wire:model="login_max_attempts" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
                        @error('login_max_attempts') <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p> @enderror
                        <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">允许的最大密码尝试次数。</p>
                    </div>
                    <div>
                        <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">锁定时长（分钟）</label>
                        <input type="number" wire:model="login_lockout_minutes" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
                        @error('login_lockout_minutes') <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p> @enderror
                        <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">账户锁定的持续时间。</p>
                    </div>
                </div>

                <div class="pt-2">
                    <button wire:click="saveSecurity" class="text-sm bg-gray-900 dark:bg-gray-100 text-white dark:text-gray-900 rounded-lg px-4 py-2">保存</button>
                </div>
            </div>

            <div x-show="tab === 'subscribe'" x-cloak class="border border-gray-200 dark:border-gray-800 rounded-xl p-5 bg-white dark:bg-gray-900 space-y-4 max-w-xl">
                <div>
                    <h2 class="font-medium text-gray-900 dark:text-gray-100">订阅设置</h2>
                    <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">管理用户订阅相关配置，包括订阅链接格式、更新频率、流量统计等设置。</p>
                </div>

                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" wire:model="subscribe_allow_plan_change">
                    允许用户更改订阅
                </label>
                <p class="text-xs text-gray-400 dark:text-gray-500 -mt-3 ml-6">开启后用户将可以对订阅计划进行变更。关闭后购买页面不再显示"更换"选项，仅专属线路本身也从不支持更改。</p>

                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" wire:model="plan_change_proration_enabled">
                    开启折抵方案
                </label>
                <p class="text-xs text-gray-400 dark:text-gray-500 -mt-3 ml-6">开启后用户更换共享套餐时，当前套餐剩余天数会按日折算抵扣新套餐价格。仅支持共享线路套餐之间的更换。</p>

                <div class="text-xs text-gray-400 dark:text-gray-500 bg-gray-50 dark:bg-gray-800/60 rounded-lg p-3">
                    专属 ISP 线路的流量按购买周期一次性发放（月付 1 份、季付 3 份、年付 12 份……），续费会把新周期的流量叠加到剩余上，用完或到期为止，不做月度重置。共享套餐的流量默认按客户各自购买日每月重置，具体重置方式在「套餐管理」里各套餐设置。
                </div>

                <div>
                    <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">订阅路径</label>
                    <input type="text" wire:model="subscribe_path" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
                    @error('subscribe_path') <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p> @enderror
                    <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">订阅路径，修改后将会改变原有的 /{{ $subscribe_path }}/xxxxxxxxxx 路径。修改后需要重启服务才能生效。</p>
                </div>

                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" wire:model="subscribe_show_userinfo">
                    在订阅中展示订阅信息
                </label>
                <p class="text-xs text-gray-400 dark:text-gray-500 -mt-3 ml-6">开启后将在客户端订阅节点时输出 Subscription-Userinfo 响应头（用量/总量/到期时间）。</p>

                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" wire:model="subscribe_show_protocol_name">
                    在订阅中线路名称中显示协议名称
                </label>
                <p class="text-xs text-gray-400 dark:text-gray-500 -mt-3 ml-6">开启后订阅线路会附带协议名称（例如：[SS]香港）。</p>

                <div class="pt-2">
                    <button wire:click="saveSubscribe" class="text-sm bg-gray-900 dark:bg-gray-100 text-white dark:text-gray-900 rounded-lg px-4 py-2">保存</button>
                </div>
            </div>

            <div x-show="tab === 'commission'" x-cloak class="border border-gray-200 dark:border-gray-800 rounded-xl p-5 bg-white dark:bg-gray-900 space-y-4 max-w-xl">
                <h2 class="font-medium text-gray-900 dark:text-gray-100">邀请&佣金设置</h2>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">佣金比例（%）</label>
                        <input type="number" wire:model="commission_rate" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
                        @error('commission_rate') <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p> @enderror
                        <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">被邀请用户每笔订单支付后，邀请人获得的佣金比例。</p>
                    </div>
                    <div>
                        <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">佣金确认周期（天）</label>
                        <input type="number" wire:model="commission_hold_days" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
                        @error('commission_hold_days') <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p> @enderror
                        <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">0 表示立即确认，可用的佣金才能划转到余额。</p>
                    </div>
                    <div>
                        <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">每人邀请码上限（个）</label>
                        <input type="number" wire:model="max_invite_codes" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
                        @error('max_invite_codes') <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p> @enderror
                        <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">每个用户最多能生成的邀请码数量。</p>
                    </div>
                </div>

                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" wire:model="invite_code_never_expires">
                    邀请码永不失效
                </label>
                <p class="text-xs text-gray-400 dark:text-gray-500 -mt-3 ml-6">开启后邀请码被使用后将不会失效，否则使用过后即失效。</p>

                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" wire:model="commission_first_payment_only">
                    佣金仅首次发放
                </label>
                <p class="text-xs text-gray-400 dark:text-gray-500 -mt-3 ml-6">开启后被邀请人首次支付时才会产生佣金。</p>

                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" wire:model="three_tier_commission_enabled">
                    三级分销
                </label>
                <p class="text-xs text-gray-400 dark:text-gray-500 -mt-3 ml-6">开启后将佣金按照设置的3成比例进行分成，三成比例合计请不要大于100%。</p>
                @error('commission_tier1_rate') <p class="text-xs text-red-600 dark:text-red-400 -mt-2 ml-6">{{ $message }}</p> @enderror

                <div class="grid grid-cols-3 gap-4">
                    <div>
                        <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">一级比例（%）</label>
                        <input type="number" wire:model="commission_tier1_rate" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
                    </div>
                    <div>
                        <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">二级比例（%）</label>
                        <input type="number" wire:model="commission_tier2_rate" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
                    </div>
                    <div>
                        <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">三级比例（%）</label>
                        <input type="number" wire:model="commission_tier3_rate" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
                    </div>
                </div>

                <div class="pt-2">
                    <button wire:click="saveCommission" class="text-sm bg-gray-900 dark:bg-gray-100 text-white dark:text-gray-900 rounded-lg px-4 py-2">保存</button>
                </div>
            </div>

            <div x-show="tab === 'node'" x-cloak class="border border-gray-200 dark:border-gray-800 rounded-xl p-5 bg-white dark:bg-gray-900 space-y-4 max-w-xl">
                <div>
                    <h2 class="font-medium text-gray-900 dark:text-gray-100">节点配置</h2>
                    <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">配置节点通信和同步设置，包括通信密钥、轮询间隔等选项。</p>
                </div>

                <div>
                    <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">通讯密钥</label>
                    <div class="flex gap-2">
                        <input type="text" wire:model="node_comm_key" class="flex-1 border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm font-mono">
                        <button type="button" wire:click="regenerateNodeKey" class="text-sm border border-gray-300 dark:border-gray-700 rounded-lg px-3 py-2 text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-800">重新生成</button>
                    </div>
                    @error('node_comm_key') <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p> @enderror
                    <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">节点与面板通讯的密钥，节点调用 <code>GET /api/nodes/config</code> 时需通过 <code>X-Node-Key</code> 请求头携带。</p>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">节点拉取动作轮询间隔（秒）</label>
                        <input type="number" wire:model="node_pull_interval" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
                        @error('node_pull_interval') <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p> @enderror
                        <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">节点从面板获取数据的间隔频率，也是 <code>/api/nodes/config</code> 返回值的一部分。</p>
                    </div>
                    <div>
                        <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">节点推送动作轮询间隔（秒）</label>
                        <input type="number" wire:model="node_push_interval" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
                        @error('node_push_interval') <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p> @enderror
                        <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">节点推送流量数据到面板的间隔频率（对应 <code>POST /api/traffic/report</code>）。</p>
                    </div>
                </div>

                <div class="pt-2">
                    <button wire:click="saveNode" class="text-sm bg-gray-900 dark:bg-gray-100 text-white dark:text-gray-900 rounded-lg px-4 py-2">保存</button>
                </div>
            </div>

            <div x-show="tab === 'provision'" x-cloak class="border border-gray-200 dark:border-gray-800 rounded-xl p-5 bg-white dark:bg-gray-900 space-y-4 max-w-xl">
                <div>
                    <h2 class="font-medium text-gray-900 dark:text-gray-100">静态 IP 开通渠道</h2>
                    <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">专属 ISP 套餐支付成功后，真正去哪里申请静态代理 IP。切到 onip 之前请先用"测试连接"确认密钥有效——切换后每次开通任务都会真实下单扣费。</p>
                </div>

                <div>
                    <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">开通方式</label>
                    @if (count($provisionDrivers) > 1)
                        <select wire:model="provisioner_driver" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
                            @foreach ($provisionDrivers as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    @else
                        <div class="flex items-center gap-2 border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/60 rounded-lg px-3 py-2 text-sm text-gray-700 dark:text-gray-300">
                            <span class="inline-block w-1.5 h-1.5 rounded-full bg-teal-500"></span>
                            {{ $provisionDrivers[$provisioner_driver] ?? reset($provisionDrivers) }}
                        </div>
                        <input type="hidden" wire:model="provisioner_driver">
                    @endif
                </div>

                <div>
                    <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">API Base URL</label>
                    <input type="text" wire:model="onip_base_url" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm font-mono">
                    @error('onip_base_url') <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p> @enderror
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">App ID</label>
                        <input type="text" wire:model="onip_app_id" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm font-mono">
                    </div>
                    <div>
                        <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">App Secret</label>
                        <input type="password" wire:model="onip_app_secret" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm font-mono">
                    </div>
                </div>

                <div class="flex gap-2 pt-2">
                    <button wire:click="saveProvision" class="text-sm bg-gray-900 dark:bg-gray-100 text-white dark:text-gray-900 rounded-lg px-4 py-2">保存</button>
                    <button wire:click="testOnipConnection" class="text-sm border border-gray-300 dark:border-gray-700 text-gray-700 dark:text-gray-300 rounded-lg px-4 py-2">测试连接（只读，不会下单）</button>
                </div>

                @php $onipCallback = rtrim($site_url ?: config('app.url'), '/') . '/api/callbacks/onip?secret=' . $onip_callback_secret; @endphp
                <div class="pt-3 border-t border-gray-100 dark:border-gray-800" x-data="{ copied: false }">
                    <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">回调地址（IP 变更 / 到期 / 续费通知，已内置校验密钥）</label>
                    <div class="flex items-center gap-2">
                        <code x-ref="onipCb" class="flex-1 text-xs bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg px-3 py-2 font-mono text-gray-700 dark:text-gray-300 break-all">{{ $onipCallback }}</code>
                        <button
                            type="button"
                            @click="navigator.clipboard.writeText($refs.onipCb.textContent.trim()); copied = true; setTimeout(() => copied = false, 1500)"
                            class="shrink-0 text-xs border border-gray-300 dark:border-gray-700 text-gray-700 dark:text-gray-300 rounded-lg px-3 py-2"
                        >
                            <span x-show="!copied">复制</span>
                            <span x-show="copied" x-cloak>已复制</span>
                        </button>
                    </div>
                    <p class="text-xs text-gray-400 dark:text-gray-500 mt-1.5">这里用的是站点网址（在「站点设置」里配置），跟你从哪个地址登录后台无关。目前开通走的是<strong>同步下单</strong>模式，<strong>回调可选、不填也能正常开通</strong>；只有当你在 onip.cc 后台启用「IP 变更 / 到期」webhook 通知时，才需要把上面这个<strong>完整地址（含 ?secret=…）</strong>填进 onip 的应用设置。<strong class="text-gray-500 dark:text-gray-400">末尾的 secret 是校验密钥，请勿删改</strong>——面板只接受带正确密钥的回调，防止他人伪造 IP 变更 / 续期。</p>
                </div>

                <p class="text-xs text-gray-400 dark:text-gray-500 pt-2 border-t border-gray-100 dark:border-gray-800">💡 这里只配 onip 供应商（怎么开通 IP）。<strong>中转节点的配置与监控</strong>（客户怎么连、主备、同步、重启）在「<strong>节点管理 → 服务器管理</strong>」的中转控制台里。</p>

            </div>

            <div x-show="tab === 'mail'" x-cloak class="border border-gray-200 dark:border-gray-800 rounded-xl p-5 bg-white dark:bg-gray-900 space-y-4 max-w-2xl">
                <div>
                    <h2 class="font-medium text-gray-900 dark:text-gray-100">邮件设置</h2>
                    <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">配置系统邮件服务，用于发送验证码、密码重置、通知等邮件，支持多种 SMTP 服务商。</p>
                </div>

                <div class="flex gap-2 border-b border-gray-200 dark:border-gray-800">
                    <button type="button" wire:click="$set('mailSubTab', 'basic')" class="text-sm px-3 py-2 border-b-2 {{ $mailSubTab === 'basic' ? 'border-teal-600 text-gray-900 dark:text-gray-100' : 'border-transparent text-gray-500 dark:text-gray-400' }}">基本设置</button>
                    <button type="button" wire:click="$set('mailSubTab', 'templates')" class="text-sm px-3 py-2 border-b-2 {{ $mailSubTab === 'templates' ? 'border-teal-600 text-gray-900 dark:text-gray-100' : 'border-transparent text-gray-500 dark:text-gray-400' }}">模板管理</button>
                </div>

                <div x-show="$wire.mailSubTab === 'basic'" class="space-y-4">
                <div>
                    <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">SMTP主机</label>
                    <input type="text" wire:model="mail_host" placeholder="smtp.gmail.com" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
                    @error('mail_host') <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p> @enderror
                    <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">SMTP服务器地址，例如：smtp.gmail.com</p>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">SMTP端口</label>
                        <input type="number" wire:model="mail_port" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
                        @error('mail_port') <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p> @enderror
                        <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">常用端口：25, 465, 587</p>
                    </div>
                    <div>
                        <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">加密方式</label>
                        <select wire:model="mail_encryption" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
                            <option value="none">无</option>
                            <option value="tls">TLS</option>
                            <option value="ssl">SSL/TLS</option>
                        </select>
                    </div>
                </div>

                <div>
                    <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">SMTP用户名</label>
                    <input type="text" wire:model="mail_username" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
                    @error('mail_username') <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">SMTP密码</label>
                    <input type="password" wire:model="mail_password" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
                    @error('mail_password') <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p> @enderror
                    <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">SMTP认证密码或应用专用密码</p>
                </div>

                <div>
                    <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">发件人地址</label>
                    <input type="text" wire:model="mail_from_address" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
                    @error('mail_from_address') <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p> @enderror
                    <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">发件人邮箱地址</p>
                </div>

                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" wire:model="mail_reminders_enabled">
                    邮件提醒
                </label>
                <p class="text-xs text-gray-400 dark:text-gray-500 -mt-3 ml-6">开启后用户订阅即将到期或流量不足时会收到邮件通知。</p>

                <div class="pt-2 flex items-center gap-2">
                    <button wire:click="saveMail" class="text-sm bg-gray-900 dark:bg-gray-100 text-white dark:text-gray-900 rounded-lg px-4 py-2">保存</button>
                    <button wire:click="sendTestMail" wire:loading.attr="disabled" wire:target="sendTestMail" class="text-sm border border-gray-300 dark:border-gray-700 text-gray-700 dark:text-gray-300 rounded-lg px-4 py-2">
                        <span wire:loading.remove wire:target="sendTestMail">发送测试邮件</span>
                        <span wire:loading wire:target="sendTestMail">发送中…</span>
                    </button>
                </div>
                <p class="text-xs text-gray-400 dark:text-gray-500">"发送测试邮件"会用上面**当前填写的** SMTP 配置，往你自己的登录邮箱发一封测试邮件，用来在保存前确认配置能通。</p>
                </div>

                <div x-show="$wire.mailSubTab === 'templates'" class="space-y-4">
                    <p class="text-xs text-gray-400 dark:text-gray-500">修改并保存后将覆盖系统默认模板。点击「恢复默认」可随时还原为默认模板。</p>

                    <div class="flex flex-wrap gap-1.5">
                        @foreach (\App\Models\EmailTemplate::LABELS as $key => $label)
                            <button
                                type="button"
                                wire:click="$set('emailTemplateKey', '{{ $key }}')"
                                class="text-xs rounded-lg px-3 py-1.5 {{ $emailTemplateKey === $key ? 'bg-gray-900 dark:bg-gray-100 text-white dark:text-gray-900' : 'bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-400' }}"
                            >{{ $label }}</button>
                        @endforeach
                    </div>

                    <div>
                        <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">邮件主题</label>
                        <input type="text" wire:model="emailTemplateSubject" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
                        @error('emailTemplateSubject') <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p> @enderror
                    </div>

                    <div class="text-xs text-gray-400 dark:text-gray-500 bg-gray-50 dark:bg-gray-800/60 rounded-lg p-3">
                        可用占位符：
                        @if ($emailTemplateKey === 'verify_email' || $emailTemplateKey === 'magic_login')
                            <code>@{{link}}</code>（链接，必填）、
                        @endif
                        @if ($emailTemplateKey === 'site_notice')
                            <code>@{{content}}</code>（通知正文，必填）、
                        @endif
                        @if ($emailTemplateKey === 'expire_reminder' || $emailTemplateKey === 'traffic_reminder')
                            <code>@{{plan_name}}</code>、
                        @endif
                        @if ($emailTemplateKey === 'expire_reminder')
                            <code>@{{expired_at}}</code>、
                        @endif
                        @if ($emailTemplateKey === 'traffic_reminder')
                            <code>@{{percent}}</code>、
                        @endif
                        <code>@{{name}}</code>（站点名称）、<code>@{{url}}</code>（站点网址）
                    </div>

                    <div>
                        <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">模板内容</label>
                        <textarea wire:model="emailTemplateContent" rows="8" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm font-mono"></textarea>
                        @error('emailTemplateContent') <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p> @enderror
                    </div>

                    <div class="pt-2 flex gap-2">
                        <button wire:click="saveEmailTemplate" class="text-sm bg-gray-900 dark:bg-gray-100 text-white dark:text-gray-900 rounded-lg px-4 py-2">保存</button>
                        <button wire:click="resetEmailTemplate" class="text-sm border border-gray-300 dark:border-gray-700 rounded-lg px-4 py-2 text-gray-600 dark:text-gray-400">恢复默认</button>
                    </div>
                </div>
            </div>

            <div x-show="tab === 'telegram'" x-cloak class="border border-gray-200 dark:border-gray-800 rounded-xl p-5 bg-white dark:bg-gray-900 space-y-4 max-w-xl">
                <h2 class="font-medium text-gray-900 dark:text-gray-100">Telegram 设置</h2>

                <div>
                    <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">讨论组链接</label>
                    <input type="text" wire:model="telegram_group_url" placeholder="https://t.me/your_group" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
                    @error('telegram_group_url') <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p> @enderror
                    <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">客户在「个人中心」点击"立即加入"会跳转到这个链接。</p>
                </div>

                <div class="pt-4 border-t border-gray-200 dark:border-gray-800">
                    <h3 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Telegram 机器人</h3>
                    <p class="text-xs text-gray-400 dark:text-gray-500 mb-3">客户可以在机器人里绑定账号，接收到期/流量提醒，用 /status、/traffic 查询状态。去 <a href="https://t.me/BotFather" target="_blank" class="underline">@BotFather</a> 创建一个机器人即可拿到 Token。</p>
                </div>

                <div>
                    <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">Bot Token</label>
                    <input type="text" wire:model="telegram_bot_token" placeholder="123456789:AAxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm font-mono">
                    @error('telegram_bot_token') <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">Bot 用户名</label>
                    <input type="text" wire:model="telegram_bot_username" placeholder="your_bot（不用带 @）" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
                    @error('telegram_bot_username') <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p> @enderror
                </div>

                <div class="pt-2">
                    <button wire:click="saveTelegram" class="text-sm bg-gray-900 dark:bg-gray-100 text-white dark:text-gray-900 rounded-lg px-4 py-2">保存</button>
                </div>
            </div>

            <div x-show="tab === 'support'" x-cloak class="border border-gray-200 dark:border-gray-800 rounded-xl p-5 bg-white dark:bg-gray-900 space-y-4 max-w-xl">
                <div>
                    <h2 class="font-medium text-gray-900 dark:text-gray-100">客服设置</h2>
                    <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">配置全站右下角悬浮「联系客服」气泡里的联系方式。填了才显示，登录前后都可见。</p>
                </div>

                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" wire:model="support_widget_enabled">
                    开启悬浮客服入口
                </label>
                <p class="text-xs text-gray-400 dark:text-gray-500 -mt-3 ml-6">关闭后整个悬浮气泡不显示（下面填了也不显示）。</p>

                <div>
                    <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">Telegram 客服号</label>
                    <input type="text" wire:model="support_telegram" placeholder="@YourSupport 或 https://t.me/YourSupport" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
                    @error('support_telegram') <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p> @enderror
                    <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">可填用户名或 t.me 链接。点击会用 tg:// 深链直接唤起 App，t.me 被墙也能打开。</p>
                </div>

                <div>
                    <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">Telegram 交流群 / 频道</label>
                    <input type="text" wire:model="support_telegram_group" placeholder="https://t.me/your_group 或 t.me/+邀请码" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
                    @error('support_telegram_group') <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">客服邮箱</label>
                    <input type="text" wire:model="support_email" placeholder="support@example.com" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
                    @error('support_email') <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p> @enderror
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    {{-- 微信：号码（点击复制）+ 可选二维码（客户点开扫码添加） --}}
                    <div class="border border-gray-100 dark:border-gray-800 rounded-lg p-3 space-y-2">
                        <label class="block text-sm text-gray-600 dark:text-gray-400">微信</label>
                        <input type="text" wire:model="support_wechat" placeholder="微信号（客户点击复制）" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
                        @error('support_wechat') <p class="text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                        <div class="flex items-center gap-3">
                            @if ($support_wechat_qr_upload)
                                <img src="{{ $support_wechat_qr_upload->temporaryUrl() }}" class="w-16 h-16 rounded-lg object-cover border border-gray-200 dark:border-gray-700">
                            @elseif ($support_wechat_qr)
                                <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($support_wechat_qr) }}" class="w-16 h-16 rounded-lg object-cover border border-gray-200 dark:border-gray-700">
                            @endif
                            <div class="flex-1 min-w-0">
                                <label class="block text-xs text-teal-700 dark:text-teal-400 cursor-pointer hover:underline">
                                    {{ $support_wechat_qr || $support_wechat_qr_upload ? '更换二维码' : '上传二维码' }}
                                    <input type="file" wire:model="support_wechat_qr_upload" accept="image/*" class="hidden">
                                </label>
                                @if ($support_wechat_qr)
                                    <button type="button" wire:click="removeWechatQr" class="text-xs text-red-500 dark:text-red-400 hover:underline mt-1">移除</button>
                                @endif
                                <div wire:loading wire:target="support_wechat_qr_upload" class="text-xs text-gray-400">上传中…</div>
                            </div>
                        </div>
                        @error('support_wechat_qr_upload') <p class="text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>

                    {{-- QQ：号码/群号（点击复制）+ 可选二维码 --}}
                    <div class="border border-gray-100 dark:border-gray-800 rounded-lg p-3 space-y-2">
                        <label class="block text-sm text-gray-600 dark:text-gray-400">QQ</label>
                        <input type="text" wire:model="support_qq" placeholder="QQ 号或群号" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
                        @error('support_qq') <p class="text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                        <div class="flex items-center gap-3">
                            @if ($support_qq_qr_upload)
                                <img src="{{ $support_qq_qr_upload->temporaryUrl() }}" class="w-16 h-16 rounded-lg object-cover border border-gray-200 dark:border-gray-700">
                            @elseif ($support_qq_qr)
                                <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($support_qq_qr) }}" class="w-16 h-16 rounded-lg object-cover border border-gray-200 dark:border-gray-700">
                            @endif
                            <div class="flex-1 min-w-0">
                                <label class="block text-xs text-teal-700 dark:text-teal-400 cursor-pointer hover:underline">
                                    {{ $support_qq_qr || $support_qq_qr_upload ? '更换二维码' : '上传二维码' }}
                                    <input type="file" wire:model="support_qq_qr_upload" accept="image/*" class="hidden">
                                </label>
                                @if ($support_qq_qr)
                                    <button type="button" wire:click="removeQqQr" class="text-xs text-red-500 dark:text-red-400 hover:underline mt-1">移除</button>
                                @endif
                                <div wire:loading wire:target="support_qq_qr_upload" class="text-xs text-gray-400">上传中…</div>
                            </div>
                        </div>
                        @error('support_qq_qr_upload') <p class="text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div>
                    <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">提示语（可选）</label>
                    <input type="text" wire:model="support_note" placeholder="如：工作时间 10:00-22:00，其余时间请提交工单" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
                    @error('support_note') <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p> @enderror
                    <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">显示在气泡顶部，留空则不显示。</p>
                </div>

                <div class="pt-2">
                    <button wire:click="saveSupport" class="text-sm bg-gray-900 dark:bg-gray-100 text-white dark:text-gray-900 rounded-lg px-4 py-2">保存</button>
                </div>
            </div>

            <div x-show="tab === 'app'" x-cloak class="border border-gray-200 dark:border-gray-800 rounded-xl p-5 bg-white dark:bg-gray-900 space-y-5 max-w-xl">
                <div>
                    <h2 class="font-medium text-gray-900 dark:text-gray-100">APP 设置</h2>
                    <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">GitHub 新版本先进入私有隔离区；核对可信 SHA-256 后才会替换客户下载地址。</p>
                </div>

                <div class="flex items-center gap-2">
                    <button wire:click="syncAllAppClients" wire:loading.attr="disabled" wire:target="syncAllAppClients" class="text-xs border border-gray-300 dark:border-gray-700 text-gray-700 dark:text-gray-300 rounded-lg px-3 py-1.5">
                        <span wire:loading.remove wire:target="syncAllAppClients">全部立即同步</span>
                        <span wire:loading wire:target="syncAllAppClients">同步中…</span>
                    </button>
                    <span class="text-xs text-gray-400 dark:text-gray-500">每天检查新版本，已发布版本在审核完成前保持不变。</span>
                </div>

                <div class="border-t border-gray-100 dark:border-gray-800 pt-4">
                    <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">下载说明（显示在客户"客户端下载"弹窗顶部）</label>
                    <textarea wire:model="app_download_note" rows="3" placeholder="例如：iOS（苹果）客户端需要美区 Apple ID 才能下载，账号请联系客服领取，登录后只登 App Store、不要登 iCloud。" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm"></textarea>
                    <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">留空则不显示。支持换行；常用于说明 iOS 需美区 Apple ID、如何导入订阅等。</p>
                    <button wire:click="saveAppDownloadNote" class="mt-2 text-sm bg-gray-900 dark:bg-gray-100 text-white dark:text-gray-900 rounded-lg px-4 py-2">保存说明</button>
                </div>

                @foreach (\App\Models\AppClient::PLATFORMS as $platform => $label)
                    <div>
                        <h3 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">{{ $label }}</h3>
                        <div class="space-y-2">
                            @forelse ($appClients[$platform] ?? [] as $client)
                                <div class="border border-gray-200 dark:border-gray-700 rounded-lg px-3 py-2 space-y-2">
                                  <div class="flex items-center justify-between gap-3">
                                    <div class="min-w-0">
                                        <div class="text-sm text-gray-800 dark:text-gray-200 truncate flex items-center gap-1.5">
                                            {{ $client->name }}
                                            @if ($client->source_type === \App\Models\AppClient::SOURCE_GITHUB)
                                                <span class="text-xs font-normal px-1.5 py-0.5 rounded bg-gray-100 dark:bg-gray-800 text-gray-500 dark:text-gray-400">隔离审核</span>
                                            @endif
                                        </div>
                                        @if ($client->source_type === \App\Models\AppClient::SOURCE_GITHUB)
                                            <div class="text-xs text-gray-400 dark:text-gray-500 truncate">
                                                {{ $client->github_repo }}
                                                @if ($client->synced_version)
                                                    · 当前 {{ $client->synced_version }}（{{ $client->synced_at?->diffForHumans() }} 同步）
                                                @else
                                                    · 还未同步成功
                                                @endif
                                            </div>
                                            @if ($client->published_sha256)
                                                <div class="text-xs text-gray-400 dark:text-gray-500 font-mono break-all mt-1">SHA-256 {{ $client->published_sha256 }}</div>
                                                <div class="text-xs mt-1 {{ $client->published_verified_at ? 'text-green-700 dark:text-green-400' : 'text-amber-700 dark:text-amber-400' }}">
                                                    {{ $client->published_verified_at ? '已通过可信哈希核验' : '历史文件尚未通过可信来源复核' }}
                                                </div>
                                            @endif
                                            @if ($client->last_sync_error)
                                                <div class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $client->last_sync_error }}</div>
                                            @endif
                                        @else
                                            <div class="text-xs text-gray-400 dark:text-gray-500 truncate">{{ $client->url }}</div>
                                        @endif
                                    </div>
                                    <div class="flex items-center gap-2 shrink-0">
                                        @if ($client->source_type === \App\Models\AppClient::SOURCE_GITHUB)
                                            <button type="button" wire:click="syncAppClientNow({{ $client->id }})" class="text-xs text-gray-500 dark:text-gray-400">立即同步</button>
                                        @endif
                                        <button type="button" wire:click="removeAppClient({{ $client->id }})" class="text-xs text-red-600 dark:text-red-400">删除</button>
                                    </div>
                                  </div>
                                  <div class="flex items-center gap-2">
                                      <input type="text" wire:model="appClientNotes.{{ $client->id }}" placeholder="下载说明小字（显示在客户端此 App 下方，如：付费·需美区 Apple ID）" class="flex-1 min-w-0 border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-2.5 py-1.5 text-xs">
                                      <button type="button" wire:click="saveAppClientNote({{ $client->id }})" class="shrink-0 text-xs border border-gray-300 dark:border-gray-700 text-gray-700 dark:text-gray-300 rounded-lg px-3 py-1.5">保存</button>
                                  </div>
                                  @if ($client->hasPendingRelease())
                                      <div class="border-t border-gray-100 dark:border-gray-800 pt-2 space-y-2">
                                          <div class="text-xs text-amber-700 dark:text-amber-400">
                                              待审核 {{ $client->pending_version }} · {{ number_format($client->pending_size / 1048576, 2) }} MB
                                          </div>
                                          <div class="text-xs text-gray-500 dark:text-gray-400 font-mono break-all">SHA-256 {{ $client->pending_sha256 }}</div>
                                          <div class="flex items-start gap-2">
                                              <div class="flex-1 min-w-0">
                                                  <input type="text" wire:model="appClientApprovalHashes.{{ $client->id }}" placeholder="可信渠道的 SHA-256" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-2.5 py-1.5 text-xs font-mono">
                                                  @error("appClientApprovalHashes.{$client->id}") <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p> @enderror
                                              </div>
                                              <button type="button" wire:click="approveAppClientRelease({{ $client->id }})" class="shrink-0 text-xs border border-green-300 dark:border-green-800 text-green-700 dark:text-green-400 rounded-lg px-3 py-1.5">核验并发布</button>
                                              <button type="button" wire:click="rejectAppClientRelease({{ $client->id }})" class="shrink-0 text-xs text-red-600 dark:text-red-400 px-1 py-1.5">丢弃</button>
                                          </div>
                                      </div>
                                  @elseif ($client->source_type === \App\Models\AppClient::SOURCE_GITHUB && $client->local_path && ! $client->published_verified_at)
                                      <div class="border-t border-gray-100 dark:border-gray-800 pt-2">
                                          <div class="flex items-start gap-2">
                                              <div class="flex-1 min-w-0">
                                                  <input type="text" wire:model="appClientApprovalHashes.{{ $client->id }}" placeholder="可信渠道的当前版本 SHA-256" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-2.5 py-1.5 text-xs font-mono">
                                                  @error("appClientApprovalHashes.{$client->id}") <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p> @enderror
                                              </div>
                                              <button type="button" wire:click="verifyPublishedAppClient({{ $client->id }})" class="shrink-0 text-xs border border-green-300 dark:border-green-800 text-green-700 dark:text-green-400 rounded-lg px-3 py-1.5">核验当前文件</button>
                                          </div>
                                      </div>
                                  @endif
                                </div>
                            @empty
                                <p class="text-xs text-gray-400 dark:text-gray-500">还没有配置</p>
                            @endforelse
                        </div>
                    </div>
                @endforeach

                <div class="pt-3 border-t border-gray-200 dark:border-gray-800 space-y-3">
                    <h3 class="text-sm font-medium text-gray-700 dark:text-gray-300">添加客户端</h3>
                    <div>
                        <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">平台</label>
                        <select wire:model="newAppClientPlatform" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
                            @foreach (\App\Models\AppClient::PLATFORMS as $platform => $label)
                                <option value="{{ $platform }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">客户端名称</label>
                        <input type="text" wire:model="newAppClientName" placeholder="例如 Clash Verge" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
                        @error('newAppClientName') <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">下载说明（可选，小字显示在客户端此 App 下方）</label>
                        <input type="text" wire:model="newAppClientNote" placeholder="例如：付费·需美区 Apple ID / 免费开源" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
                    </div>

                    <div>
                        <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">下载地址来源</label>
                        <select wire:model.live="newAppClientSourceType" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
                            <option value="manual">手动填写链接</option>
                            <option value="github">GitHub 隔离同步（核验 SHA-256 后发布）</option>
                        </select>
                    </div>

                    @if ($newAppClientSourceType === 'github')
                        <div>
                            <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">GitHub 仓库</label>
                            <input type="text" wire:model="newAppClientGithubRepo" placeholder="例如 2dust/v2rayN" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
                            @error('newAppClientGithubRepo') <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">文件名匹配规则（PHP 正则）</label>
                            <input type="text" wire:model="newAppClientAssetPattern" placeholder="例如 /^v2rayN-windows-64\.zip$/" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm font-mono">
                            @error('newAppClientAssetPattern') <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p> @enderror
                            <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">每次发新版本，release 里通常有很多个文件（不同平台/架构），这个规则用来从里面挑出你要的那一个。可以先打开该项目 GitHub 的 Releases 页面看一下文件名长什么样再填。</p>
                        </div>
                    @else
                        <div>
                            <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">下载地址</label>
                            <input type="text" wire:model="newAppClientUrl" placeholder="下载页 / App Store / TestFlight 链接" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
                            @error('newAppClientUrl') <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p> @enderror
                        </div>
                    @endif

                    <button wire:click="addAppClient" class="text-sm bg-gray-900 dark:bg-gray-100 text-white dark:text-gray-900 rounded-lg px-4 py-2">添加</button>
                </div>
            </div>

            <div x-show="tab === 'subscribe-template'" x-cloak class="border border-gray-200 dark:border-gray-800 rounded-xl p-5 bg-white dark:bg-gray-900 space-y-4 max-w-2xl">
                <div>
                    <h2 class="font-medium text-gray-900 dark:text-gray-100">订阅模板</h2>
                    <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">配置各个客户端的订阅模板，节点会按照该客户端的配置格式自动生成。</p>
                </div>

                <div class="flex flex-wrap gap-1.5">
                    @foreach (\App\Models\SubscribeTemplate::LABELS as $client => $label)
                        <button
                            type="button"
                            wire:click="$set('subscribeTemplateClient', '{{ $client }}')"
                            class="text-xs rounded-lg px-3 py-1.5 {{ $subscribeTemplateClient === $client ? 'bg-gray-900 dark:bg-gray-100 text-white dark:text-gray-900' : 'bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-400' }}"
                        >{{ $label }}</button>
                    @endforeach
                </div>

                <div class="text-xs text-gray-400 dark:text-gray-500 bg-gray-50 dark:bg-gray-800/60 rounded-lg p-3">
                    可用占位符：<code>@{{proxies}}</code>（节点配置，会按此客户端格式自动生成）、<code>@{{proxy_names}}</code>（节点名称列表，用于分组引用）、<code>@{{subs_link}}</code>（订阅链接）、<code>@{{subscribe_info}}</code>（订阅信息）
                </div>

                <div>
                    <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">{{ \App\Models\SubscribeTemplate::LABELS[$subscribeTemplateClient] }} 订阅模板</label>
                    <textarea wire:model="subscribeTemplateContent" rows="14" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm font-mono"></textarea>
                    @error('subscribeTemplateContent') <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p> @enderror
                </div>

                <div class="pt-2 flex gap-2">
                    <button wire:click="saveSubscribeTemplate" class="text-sm bg-gray-900 dark:bg-gray-100 text-white dark:text-gray-900 rounded-lg px-4 py-2">保存</button>
                    <button wire:click="resetSubscribeTemplate" class="text-sm border border-gray-300 dark:border-gray-700 rounded-lg px-4 py-2 text-gray-600 dark:text-gray-400">恢复默认</button>
                </div>
            </div>
        </div>
    </div>
</div>
