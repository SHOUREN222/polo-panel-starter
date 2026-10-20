<?php

use App\Models\AppClient;
use App\Models\Notice;
use App\Models\Setting;
use App\Services\SharedNodeService;
use App\Services\SubscriptionLinkService;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.app')] class extends Component
{
    public function with(): array
    {
        $user = auth()->user();
        $sharedNodeService = app(SharedNodeService::class);
        $servers = $sharedNodeService->getAvailableServers($user);
        $instances = $user->subscriptionInstances()->with('plan')->latest()->get();
        $sharedUsedBytes = $servers->count() ? $sharedNodeService->sharedTrafficUsedBytes($user) : 0;
        // Effective quota = plan base + purchased traffic packs, so the card
        // reflects packs the customer bought (not just the plan's base quota).
        $sharedQuotaBytes = $servers->count() ? $sharedNodeService->effectiveSharedQuotaBytes($user) : 0;

        $clientDownloads = AppClient::whereNotNull('url')->where('url', '!=', '')
            ->orderBy('platform')->orderBy('sort')->orderBy('id')->get()->groupBy('platform');

        return [
            'subscriptionUrl' => $user->subscriptionUrl(),
            // Raw per-node share-links (vless://.../ss://...) for customers
            // whose client can't FETCH the subscription URL (a direct,
            // unproxied request their local network interferes with — the
            // recurring "导入订阅失败" case). Pasting these needs no fetch.
            'nodeLinks' => app(SubscriptionLinkService::class)->buildLinksForUser($user),
            // Only offer the one-click aggregate import when there's actually
            // something live to import — an expired-only line still shows its
            // card (to renew), but a dead import button would just confuse.
            'hasImportableLines' => $servers->count() > 0 || $instances->contains(fn ($instance) => $instance->isAvailable()),
            // 订阅数 = 下面渲染的卡片数：一个共享套餐算 1 个订阅（它有几个节点在卡片里
            // 单独写），加上每条专属 ISP 线路。不要把共享节点数摊进来——它们是「多人
            // 共享」的节点，不是独立线路，那样头部数字还会和卡片数对不上。
            'subscriptionCount' => ($servers->count() ? 1 : 0) + $instances->count(),
            'notices' => Notice::where('show', true)->orderBy('sort')->orderByDesc('created_at')->get(),
            'servers' => $servers,
            'instances' => $instances,
            'sharedUsedBytes' => $sharedUsedBytes,
            'sharedQuotaBytes' => $sharedQuotaBytes,
            'user' => $user,
            'siteName' => Setting::get('site_name', config('app.name')),
            'clientDownloads' => $clientDownloads,
            'ticketSystemEnabled' => Setting::getBool('ticket_system_enabled', true),
        ];
    }
};
?>

<div class="space-y-6">
    <div>
        <h1 class="text-xl font-semibold text-gray-900 dark:text-gray-100">欢迎回来，{{ auth()->user()->name }}</h1>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">当前账号共 {{ $subscriptionCount }} 个订阅。</p>
    </div>

    @if ($notices->count())
        <div class="border border-gray-200 dark:border-gray-800 rounded-xl bg-white dark:bg-gray-900 divide-y divide-gray-100 dark:divide-gray-800">
            <h2 class="text-sm font-medium text-gray-500 dark:text-gray-400 px-5 py-3 flex items-center gap-2">
                @include('partials.icon', ['name' => 'megaphone'])
                公告
            </h2>
            @foreach ($notices as $notice)
                <div class="px-5 py-4">
                    <div class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ $notice->title }}</div>
                    <div class="text-xs text-gray-500 dark:text-gray-400 mt-1 whitespace-pre-line">{{ $notice->content }}</div>
                </div>
            @endforeach
        </div>
    @endif

    {{-- 我的订阅 --}}
    <div>
        <h2 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">我的订阅</h2>

        @if ($servers->count() || $instances->count())
            {{-- 一键订阅：一个链接就导入账号下的全部节点（共享 + 专属），无需逐条订阅 --}}
            @if ($hasImportableLines)
            <div class="rounded-2xl p-6 bg-gradient-to-br from-[var(--brand)] to-[var(--brand-dark)] text-white relative overflow-hidden mb-4">
                <span class="absolute top-5 right-5 text-xs font-medium px-2.5 py-1 rounded-full bg-white/15">推荐</span>
                <div class="text-xs font-medium text-white/80 mb-1">一键订阅</div>
                <h3 class="text-lg font-semibold mb-1">一个链接，导入你全部节点</h3>
                <p class="text-sm text-white/70 mb-5 max-w-md">复制这一条订阅地址，或直接导入到客户端——你账号下的机房共享节点和静态 ISP 线路都在里面，不用逐条添加。</p>
                <div class="flex flex-wrap gap-2 items-center">
                    @include('partials.subscribe-button', [
                        'url' => $subscriptionUrl,
                        'nodeLinks' => $nodeLinks,
                        'siteName' => $siteName,
                        'buttonClass' => 'text-xs font-medium bg-white text-[var(--brand-dark)] rounded-lg px-4 py-2',
                        'buttonLabel' => '一键订阅',
                    ])
                </div>
            </div>
            @endif

            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @php
                    $sharedQuota = $sharedQuotaBytes;
                    $sharedRemaining = max(0, $sharedQuota - $sharedUsedBytes);
                @endphp
                @if ($servers->count())
                    @include('partials.subscription-card', [
                        'kind' => '基础套餐',
                        'title' => $user->plan->name ?? '基础套餐',
                        'status' => 'active',
                        'statusLabel' => '运行中',
                        'meta' => [
                            '到期时间' => $user->plan_expired_at?->format('Y-m-d H:i'),
                            '剩余流量' => round($sharedRemaining / 1073741824, 2) . ' GB',
                            '已用流量' => round($sharedUsedBytes / 1073741824, 2) . ' GB',
                            '总流量' => round($sharedQuota / 1073741824) . ' GB',
                            '节点数' => $servers->count() . ' 个',
                            '设备限制' => $user->plan?->device_limit ? $user->plan->device_limit . ' 台' : '未限制',
                        ],
                        'siteName' => $siteName,
                    ])
                @endif

                @foreach ($instances as $instance)
                    @php
                        $isExpired = $instance->isExpired();
                        $statusLabel = match (true) {
                            $isExpired => '已过期',
                            $instance->status === 'active' => '运行中',
                            $instance->status === 'pending' => '开通中',
                            $instance->status === 'suspended' => '流量用尽',
                            default => '已停用',
                        };
                        $instanceQuota = $instance->effectiveQuotaBytes();
                        $remaining = $instance->getRemainingTraffic();
                    @endphp
                    @include('partials.subscription-card', [
                        'kind' => '专属线路',
                        'title' => $instance->plan->name,
                        'status' => $isExpired ? 'expired' : $instance->status,
                        'statusLabel' => $statusLabel,
                        'meta' => [
                            '到期时间' => $instance->expired_at?->format('Y-m-d H:i'),
                            '剩余流量' => round($remaining / 1073741824, 2) . ' GB',
                            '已用流量' => round(($instance->u + $instance->d) / 1073741824, 2) . ' GB',
                            '总流量' => round($instanceQuota / 1073741824) . ' GB',
                            '设备限制' => $instance->plan->device_limit ? $instance->plan->device_limit . ' 台' : '未限制',
                            'IP' => $instance->meta['ip'] ?? '-',
                        ],
                        'siteName' => $siteName,
                    ])
                @endforeach
            </div>
        @else
            <p class="text-sm text-gray-500 dark:text-gray-400">还没有任何订阅，去<a href="/plans" class="underline">购买订阅</a>选一个套餐开始吧。</p>
        @endif
    </div>

    {{-- 捷径 --}}
    <div
        x-data="{ downloadOpen: false }"
        class="relative border border-gray-200 dark:border-gray-800 rounded-xl bg-white dark:bg-gray-900 divide-y divide-gray-100 dark:divide-gray-800"
    >
        <h2 class="text-sm font-medium text-gray-500 dark:text-gray-400 px-5 py-3">捷径</h2>

        <a href="/knowledge" class="flex items-center justify-between px-5 py-4 hover:bg-gray-50 dark:hover:bg-gray-800/60">
            <div>
                <div class="text-sm font-medium text-gray-900 dark:text-gray-100">查看教程</div>
                <div class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">学习如何使用 {{ $siteName }}</div>
            </div>
            @include('partials.icon', ['name' => 'book'])
        </a>

        @if ($clientDownloads->count())
            <button type="button" @click="downloadOpen = true" class="w-full flex items-center justify-between px-5 py-4 hover:bg-gray-50 dark:hover:bg-gray-800/60 text-left">
                <div>
                    <div class="text-sm font-medium text-gray-900 dark:text-gray-100">客户端下载</div>
                    <div class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">下载 {{ $siteName }} 客户端，导入订阅即可使用</div>
                </div>
                @include('partials.icon', ['name' => 'download'])
            </button>
        @endif

        @if ($ticketSystemEnabled)
            <a href="/tickets?new=1" class="flex items-center justify-between px-5 py-4 hover:bg-gray-50 dark:hover:bg-gray-800/60">
                <div>
                    <div class="text-sm font-medium text-gray-900 dark:text-gray-100">遇到问题</div>
                    <div class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">遇到问题可以通过工单与我们沟通</div>
                </div>
                @include('partials.icon', ['name' => 'ticket'])
            </a>
        @endif

        {{-- 客户端下载弹窗 --}}
        @if ($clientDownloads->count())
            <div
                x-show="downloadOpen"
                x-cloak
                @keydown.escape.window="downloadOpen = false"
                class="fixed inset-0 z-50 flex items-center justify-center p-4"
            >
                <div @click="downloadOpen = false" class="absolute inset-0 bg-black/50"></div>

                <div class="relative bg-white dark:bg-gray-900 rounded-xl w-full max-w-sm max-h-[85vh] overflow-y-auto shadow-xl">
                    <h3 class="text-sm font-medium text-gray-900 dark:text-gray-100 px-5 pt-5 pb-1">客户端下载</h3>
                    <p class="text-xs text-gray-400 dark:text-gray-500 px-5 pb-3">以下为推荐的第三方开源代理客户端</p>
                    @php $appDownloadNote = \App\Models\Setting::get('app_download_note', ''); @endphp
                    @if ($appDownloadNote !== '')
                        <div class="mx-5 mb-3 text-xs text-amber-700 dark:text-amber-400 bg-amber-50 dark:bg-amber-500/10 border border-amber-100 dark:border-amber-500/20 rounded-lg px-3 py-2 whitespace-pre-line">{{ $appDownloadNote }}</div>
                    @endif
                    @foreach (\App\Models\AppClient::PLATFORMS as $platform => $label)
                        @if (($clientDownloads[$platform] ?? null)?->count())
                            <div class="px-5 pt-3 pb-1 text-xs font-medium text-gray-400 dark:text-gray-500">{{ $label }}</div>
                            <div class="divide-y divide-gray-100 dark:divide-gray-800">
                                @foreach ($clientDownloads[$platform] as $client)
                                    <a href="{{ $client->url }}" target="_blank" class="flex items-center justify-between gap-3 px-5 py-3.5 hover:bg-gray-50 dark:hover:bg-gray-800/60">
                                        <span class="min-w-0">
                                            <span class="block text-sm text-gray-800 dark:text-gray-200">{{ $client->name }}</span>
                                            @if ($client->note)
                                                <span class="block text-xs text-gray-400 dark:text-gray-500 mt-0.5">{{ $client->note }}</span>
                                            @endif
                                            @if ($client->source_type === \App\Models\AppClient::SOURCE_GITHUB && $client->published_sha256)
                                                <span class="block text-xs text-gray-400 dark:text-gray-500 mt-0.5 font-mono break-all">SHA-256 {{ $client->published_sha256 }}</span>
                                            @endif
                                        </span>
                                        @include('partials.icon', ['name' => 'download'])
                                    </a>
                                @endforeach
                            </div>
                        @endif
                    @endforeach
                    <button type="button" @click="downloadOpen = false" class="block w-full text-center text-sm text-gray-500 dark:text-gray-400 px-5 py-3.5 border-t border-gray-100 dark:border-gray-800">关闭</button>
                </div>
            </div>
        @endif
    </div>
</div>
