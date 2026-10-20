@php
    $navActive = 'flex items-center gap-2.5 px-3 py-2 rounded-lg font-medium bg-[var(--brand-soft)] text-[var(--brand)]';
    $navInactive = 'flex items-center gap-2.5 px-3 py-2 rounded-lg text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800';
    $referralEnabled = \App\Models\Setting::getBool('referral_enabled', true);
    $ticketSystemEnabled = \App\Models\Setting::getBool('ticket_system_enabled', true);
@endphp
<nav class="flex-1 overflow-y-auto py-4 px-3 space-y-5 text-sm">
    <div>
        <a href="/" class="{{ request()->is('/') ? $navActive : $navInactive }}">
            @include('partials.icon', ['name' => 'home'])
            仪表盘
        </a>
        <a href="/knowledge" class="{{ request()->is('knowledge') ? $navActive : $navInactive }}">
            @include('partials.icon', ['name' => 'book'])
            使用文档
        </a>
    </div>

    <div>
        <div class="px-3 mb-1.5 text-xs font-medium text-gray-400 dark:text-gray-500 uppercase tracking-wide">财务</div>
        <a href="/orders" class="{{ request()->is('orders') ? $navActive : $navInactive }}">
            @include('partials.icon', ['name' => 'list'])
            我的订单
        </a>
        @if ($referralEnabled)
            <a href="/invite" class="{{ request()->is('invite') ? $navActive : $navInactive }}">
                @include('partials.icon', ['name' => 'invite'])
                我的邀请
            </a>
        @endif
    </div>

    @if (auth()->user()?->isAgent())
        <div>
            <div class="px-3 mb-1.5 text-xs font-medium text-gray-400 dark:text-gray-500 uppercase tracking-wide">代理</div>
            <a href="/agent" class="{{ request()->is('agent') ? $navActive : $navInactive }}">
                @include('partials.icon', ['name' => 'card'])
                代理中心
            </a>
        </div>
    @endif

    <div>
        <div class="px-3 mb-1.5 text-xs font-medium text-gray-400 dark:text-gray-500 uppercase tracking-wide">订阅</div>
        <a href="/plans" class="{{ request()->is('plans') ? $navActive : $navInactive }}">
            @include('partials.icon', ['name' => 'cart'])
            购买订阅
        </a>
        <a href="/renew" class="{{ request()->is('renew') ? $navActive : $navInactive }}">
            @include('partials.icon', ['name' => 'list'])
            续费订阅
        </a>
    </div>

    <div>
        <div class="px-3 mb-1.5 text-xs font-medium text-gray-400 dark:text-gray-500 uppercase tracking-wide">用户</div>
        <a href="/profile" class="{{ request()->is('profile') ? $navActive : $navInactive }}">
            @include('partials.icon', ['name' => 'user'])
            个人中心
        </a>
        @if ($ticketSystemEnabled)
            <a href="/tickets" class="{{ request()->is('tickets') ? $navActive : $navInactive }}">
                @include('partials.icon', ['name' => 'ticket'])
                我的工单
            </a>
        @endif
        <a href="/traffic" class="{{ request()->is('traffic') ? $navActive : $navInactive }}">
            @include('partials.icon', ['name' => 'chart'])
            流量明细
        </a>
    </div>
</nav>

<div class="p-3 border-t border-gray-200 dark:border-gray-800">
    <form method="POST" action="{{ route('logout') }}">
        @csrf
        <button type="submit" class="w-full flex items-center gap-2.5 px-3 py-2 rounded-lg text-gray-500 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-800 text-sm">
            @include('partials.icon', ['name' => 'logout'])
            退出登录
        </button>
    </form>
</div>
