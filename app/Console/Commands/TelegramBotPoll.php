<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use App\Services\SharedNodeService;
use App\Services\TelegramService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('telegram:poll {--once : Process one batch of pending updates and exit, instead of looping forever}')]
#[Description('Long-poll the Telegram Bot API for /bind, /status and /traffic commands')]
class TelegramBotPoll extends Command
{
    /**
     * The 分站 whose bot is currently being polled, or null for the platform's
     * own bot. Decides which customers this bot is allowed to see — see
     * onUserSite().
     */
    private ?Tenant $scope = null;

    public function handle(TelegramService $telegram): int
    {
        if (! Setting::get('telegram_bot_token')) {
            $this->error('Telegram Bot Token 还没在后台「系统设置 → Telegram 设置」里配置，无法启动轮询。');

            return self::FAILURE;
        }

        // Register the "/" command menu so it matches what we actually handle
        // (the old Xboard integration left stale /getlatesturl, /unbind entries).
        // Idempotent; only in the long-running poller, not the --once batch mode
        // (which is used by tests/manual runs and shouldn't touch bot config).
        if (! $this->option('once')) {
            try {
                $telegram->setMyCommands($this->botCommands());
            } catch (\Throwable) {
                //
            }
        }

        $this->info('Listening for Telegram updates. Press Ctrl+C to stop.');

        do {
            if (! Setting::getBool('plugin.telegram_integration_enabled', true)) {
                $this->info('Telegram 集成已在插件管理里禁用，停止处理命令。');

                return self::SUCCESS;
            }

            $ownBots = $this->tenantsWithOwnBot();

            // One long poll per bot, so the wait has to be shared out — with a
            // single bot keep the original 25s (cheap and responsive), otherwise
            // go short so a full cycle across every bot still takes seconds.
            $timeout = $this->option('once') ? 0 : (count($ownBots) === 0 ? 25 : 2);

            // 主站's bot. It also answers for any 分站 that hasn't set up one.
            $this->pollBot(null, $telegram, $timeout);

            // Each 分站 running its own bot, in its own context: its token, its
            // own poll cursor, and — see onUserSite — only its own customers.
            foreach ($ownBots as $tenant) {
                $tenant->run(fn () => $this->pollBot($tenant, $telegram, $timeout));
            }
        } while (! $this->option('once'));

        return self::SUCCESS;
    }

    /** 分站 that configured their own bot token (so the platform bot leaves them alone). */
    private function tenantsWithOwnBot(): array
    {
        $own = [];

        foreach (Tenant::all() as $tenant) {
            $tenant->run(function () use ($tenant, &$own) {
                if (Setting::get('telegram_bot_token')) {
                    $own[] = $tenant;
                }
            });
        }

        return $own;
    }

    /** Drain one bot's updates. Must already be in $scope's context. */
    private function pollBot(?Tenant $scope, TelegramService $telegram, int $timeout): void
    {
        $this->scope = $scope;

        try {
            $offset = (int) Setting::get('telegram_poll_offset', 0);
            $updates = $telegram->getUpdates($offset, $timeout);

            foreach ($updates as $update) {
                $this->handleUpdate($update, $telegram);
                Setting::set('telegram_poll_offset', (string) ($update['update_id'] + 1));
            }
        } catch (\Throwable $e) {
            // One agent's misconfigured token must not take the whole poller —
            // and every other site's bot — down with it.
            report($e);
        } finally {
            $this->scope = null;
        }
    }

    private function handleUpdate(array $update, TelegramService $telegram): void
    {
        $message = $update['message'] ?? null;

        if (! $message || ! isset($message['text'])) {
            return;
        }

        $chatId = $message['chat']['id'];
        $username = $message['from']['username'] ?? null;
        $text = trim($message['text']);
        [$command, $argument] = array_pad(explode(' ', $text, 2), 2, null);
        $argument = $argument !== null ? trim($argument) : null;

        match (true) {
            str_starts_with($command, '/start') => $argument
                ? $this->tryBind($chatId, $username, $argument, $telegram)
                : $telegram->sendMessage($chatId, $this->welcomeText($chatId)),
            str_starts_with($command, '/bind') => $argument
                ? $this->tryBind($chatId, $username, $argument, $telegram)
                : $telegram->sendMessage($chatId, "用法：<code>/bind 绑定码</code>\n\n请先到网站「个人中心 → 绑定 Telegram」生成绑定码（15 分钟内有效）。"),
            str_starts_with($command, '/status') => $this->withBoundUser($chatId, $telegram, fn (User $u) => $this->sendStatus($u, $chatId, $telegram)),
            str_starts_with($command, '/traffic') => $this->withBoundUser($chatId, $telegram, fn (User $u) => $this->sendTraffic($u, $chatId, $telegram)),
            str_starts_with($command, '/getlatesturl') => $this->withBoundUser($chatId, $telegram, fn (User $u) => $this->sendSubscription($u, $chatId, $telegram)),
            str_starts_with($command, '/unbind') => $this->withBoundUser($chatId, $telegram, fn (User $u) => $this->doUnbind($u, $chatId, $telegram)),
            str_starts_with($command, '/help') => $telegram->sendMessage($chatId, $this->welcomeText($chatId)),
            default => $telegram->sendMessage($chatId, $this->menuText()),
        };
    }

    private function siteName(): string
    {
        return Setting::get('site_name', config('app.name'));
    }

    /**
     * The command menu registered with Telegram (the "/" popup). Kept in sync
     * with the handlers in handleUpdate() so nothing shown there is a dead end.
     *
     * @return array<int, array{command: string, description: string}>
     */
    private function botCommands(): array
    {
        return [
            ['command' => 'status', 'description' => '账户信息（余额 / 套餐 / 订阅链接）'],
            ['command' => 'traffic', 'description' => '流量使用情况'],
            ['command' => 'getlatesturl', 'description' => '获取订阅链接'],
            ['command' => 'bind', 'description' => '绑定网站账号'],
            ['command' => 'unbind', 'description' => '解绑账号'],
            ['command' => 'help', 'description' => '帮助'],
        ];
    }

    /**
     * Resolve the user a Telegram message belongs to, within the reach of the
     * bot currently being polled, and run the action in that user's own site so
     * saves and relations hit the right database.
     *
     * Reach depends on whose bot it is:
     *  - a 分站's own bot sees **only that 分站's** customers. An agent must not
     *    be able to bind, inspect or unbind 主站 accounts (or another agent's).
     *  - the platform's bot serves 主站, plus any 分站 that hasn't set up a bot
     *    of its own — otherwise those customers would silently lose Telegram.
     *    主站 is tried first and short-circuits, so its behaviour is unchanged.
     *
     * @param  \Closure():?User  $find    runs in whichever site is being searched
     * @param  \Closure(User):void  $action  runs in that same site's context
     */
    private function onUserSite(\Closure $find, \Closure $action): bool
    {
        if ($this->scope !== null) {
            // Already inside that 分站's context — it is the whole world here.
            if ($user = $find()) {
                $action($user);

                return true;
            }

            return false;
        }

        if ($user = $find()) {
            $action($user);

            return true;
        }

        $ownBotIds = array_map(fn (Tenant $t) => $t->getTenantKey(), $this->tenantsWithOwnBot());

        foreach (Tenant::all() as $tenant) {
            if (in_array($tenant->getTenantKey(), $ownBotIds, true)) {
                continue; // has its own bot; that bot answers for it
            }

            $found = false;
            $tenant->run(function () use ($find, $action, &$found) {
                if ($user = $find()) {
                    $action($user);
                    $found = true;
                }
            });

            if ($found) {
                return true;
            }
        }

        return false;
    }

    private function welcomeText(int|string $chatId): string
    {
        $status = "🔗 还未绑定账号。请到网站「个人中心 → 绑定 Telegram」生成绑定码，再发送 <code>/bind 绑定码</code>。";

        $this->onUserSite(
            fn () => User::where('telegram_id', $chatId)->first(),
            function (User $bound) use (&$status) {
                $status = "✅ 已绑定账号：<b>{$bound->email}</b>";
            }
        );

        return "👋 欢迎使用 <b>{$this->siteName()}</b> 助手\n\n"
            .$status."\n\n"
            .$this->menuText();
    }

    private function menuText(): string
    {
        return "📋 <b>可用指令</b>\n"
            ."<code>/status</code> — 账户信息（余额 / 套餐 / 订阅链接）\n"
            ."<code>/traffic</code> — 流量使用情况\n"
            ."<code>/getlatesturl</code> — 获取订阅链接\n"
            ."<code>/bind 绑定码</code> — 绑定网站账号\n"
            ."<code>/unbind</code> — 解绑账号\n"
            ."<code>/help</code> — 显示本帮助";
    }

    private function gb(int|float $bytes): string
    {
        return number_format(max(0, $bytes) / 1073741824, 2);
    }

    /** A 10-segment text progress bar, e.g. ▰▰▰▱▱▱▱▱▱▱. */
    private function bar(float $ratio): string
    {
        $ratio = max(0.0, min(1.0, $ratio));
        $filled = (int) round($ratio * 10);

        return str_repeat('▰', $filled).str_repeat('▱', 10 - $filled);
    }

    private function tryBind(int|string $chatId, ?string $username, string $code, TelegramService $telegram): void
    {
        // Clear a previous binding wherever it lives, so one Telegram account
        // can't end up pointing at users in two different sites.
        $this->onUserSite(
            fn () => User::where('telegram_id', $chatId)->first(),
            fn (User $existing) => $existing->unbindTelegram()
        );

        $bound = $this->onUserSite(
            fn () => User::where('telegram_bind_code', strtoupper($code))
                ->where('telegram_bind_code_expires_at', '>', now())
                ->first(),
            function (User $user) use ($chatId, $username, $telegram) {
                $user->bindTelegram((int) $chatId, $username);
                $telegram->sendMessage($chatId, "✅ <b>绑定成功</b>\n\n账号 <b>{$user->email}</b> 已关联到这个 Telegram。\n之后到期 / 流量提醒和工单回复会自动推送到这里。\n\n发送 <code>/status</code> 或 <code>/traffic</code> 随时查询。");
            }
        );

        if (! $bound) {
            $telegram->sendMessage($chatId, "❌ 绑定码无效或已过期。\n\n请重新到网站「个人中心 → 绑定 Telegram」生成绑定码（15 分钟内有效）。");
        }
    }

    private function withBoundUser(int|string $chatId, TelegramService $telegram, \Closure $callback): void
    {
        $found = $this->onUserSite(
            fn () => User::where('telegram_id', $chatId)->first(),
            fn (User $user) => $callback($user)
        );

        if (! $found) {
            $telegram->sendMessage($chatId, "🔗 这个 Telegram 还没有绑定账号。\n\n请到网站「个人中心 → 绑定 Telegram」生成绑定码，然后发送 <code>/bind 绑定码</code>。");
        }
    }

    private function sendSubscription(User $user, int|string $chatId, TelegramService $telegram): void
    {
        $telegram->sendMessage($chatId, "<b>🔗 你的订阅链接</b>\n\n<code>{$this->subscriptionUrlFor($user)}</code>\n\n复制到你的代理客户端导入即可使用。");
    }

    /**
     * subscriptionUrl() falls back to route(), which in a console process has
     * no request to take the host from and so uses APP_URL — the 主站 domain.
     * For a 分站 customer that link would expose the platform (and wear the
     * wrong brand), so swap in their own site's domain.
     */
    private function subscriptionUrlFor(User $user): string
    {
        $url = $user->subscriptionUrl();

        if (! tenant()) {
            return $url;
        }

        // Swap in the 分站's own host, keeping the path/token as generated.
        return preg_replace('#^https?://[^/]+#', \App\Support\SiteUrl::current(), $url);
    }

    private function doUnbind(User $user, int|string $chatId, TelegramService $telegram): void
    {
        $user->unbindTelegram();
        $telegram->sendMessage($chatId, "✅ 已解绑账号 <b>{$user->email}</b>。\n\n之后不再向这里推送提醒。需要重新绑定时，到网站「个人中心 → 绑定 Telegram」生成绑定码，发送 <code>/bind 绑定码</code> 即可。");
    }

    private function sendStatus(User $user, int|string $chatId, TelegramService $telegram): void
    {
        $lines = [
            '<b>📊 账户信息</b>',
            '',
            "👤 账号：<b>{$user->email}</b>",
            '💰 余额：<b>¥'.number_format($user->balance / 100, 2).'</b>',
        ];

        $planLines = [];

        if ($user->hasActiveSharedPlan()) {
            $svc = app(SharedNodeService::class);
            $used = $svc->sharedTrafficUsedBytes($user);
            $total = $svc->effectiveSharedQuotaBytes($user);
            $traffic = $total > 0 ? "，已用 {$this->gb($used)}/{$this->gb($total)} GB" : '';
            $planLines[] = "🖥 {$user->plan->name}\n   到期 {$user->plan_expired_at->format('Y-m-d H:i')}{$traffic}";
        }

        foreach ($user->subscriptionInstances()->with('plan')->get() as $instance) {
            $expiry = $instance->expired_at?->format('Y-m-d H:i') ?? '-';
            $planLines[] = "🌐 {$instance->plan->name}\n   到期 {$expiry}";
        }

        if ($planLines) {
            $lines[] = '';
            $lines[] = '<b>套餐 / 线路</b>';
            foreach ($planLines as $pl) {
                $lines[] = $pl;
            }
        } else {
            $lines[] = '';
            $lines[] = '暂无有效套餐。';
        }

        $lines[] = '';
        $lines[] = '<b>🔗 订阅链接</b>';
        $lines[] = "<code>{$user->subscriptionUrl()}</code>";
        $lines[] = '';
        $lines[] = '发送 <code>/traffic</code> 查看流量明细。';

        $telegram->sendMessage($chatId, implode("\n", $lines));
    }

    private function sendTraffic(User $user, int|string $chatId, TelegramService $telegram): void
    {
        $blocks = [];

        if ($user->hasActiveSharedPlan()) {
            $svc = app(SharedNodeService::class);
            $used = $svc->sharedTrafficUsedBytes($user);
            $total = $svc->effectiveSharedQuotaBytes($user);
            if ($total > 0) {
                $ratio = $used / $total;
                $percent = (int) round($ratio * 100);
                $blocks[] = "🖥 <b>{$user->plan->name}</b>\n"
                    ."{$this->bar($ratio)} {$percent}%\n"
                    ."已用 {$this->gb($used)} GB / 共 {$this->gb($total)} GB · 剩 {$this->gb(max(0, $total - $used))} GB";
            }
        }

        foreach ($user->subscriptionInstances()->with('plan')->where('transfer_enable', '>', 0)->get() as $instance) {
            $ratio = $instance->usageRatio();
            $percent = (int) round($ratio * 100);
            $used = (int) $instance->u + (int) $instance->d;
            $quota = $instance->effectiveQuotaBytes();
            $blocks[] = "🌐 <b>{$instance->plan->name}</b>\n"
                ."{$this->bar($ratio)} {$percent}%\n"
                ."已用 {$this->gb($used)} GB / 共 {$this->gb($quota)} GB · 剩 {$this->gb($instance->getRemainingTraffic())} GB";
        }

        if (! $blocks) {
            $telegram->sendMessage($chatId, '暂无可统计流量的套餐或线路。');

            return;
        }

        $telegram->sendMessage($chatId, "<b>📈 流量使用</b>\n\n".implode("\n\n", $blocks));
    }
}
