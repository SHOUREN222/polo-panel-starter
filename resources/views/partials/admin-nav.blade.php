@php
    $adminBase = '/' . config('admin.path');
    $admin = auth()->user();
    $navLink = fn (string $path, string $icon, string $label, string $route) => [
        'path' => $path, 'icon' => $icon, 'label' => $label, 'route' => $route,
    ];
    $navGroups = [
        '系统管理' => [
            $navLink("$adminBase/branding", 'palette', '品牌设置', 'admin.branding'),
            $navLink("$adminBase/system", 'sliders', '系统配置', 'admin.system'),
            $navLink("$adminBase/plugins", 'box', '插件管理', 'admin.plugins'),
            $navLink("$adminBase/theme", 'palette', '主题配置', 'admin.theme'),
            $navLink("$adminBase/notices", 'megaphone', '公告管理', 'admin.notices'),
            $navLink("$adminBase/payments", 'card', '支付配置', 'admin.payments'),
            $navLink("$adminBase/knowledge", 'book', '知识库管理', 'admin.knowledge'),
        ],
        '节点管理' => [
            $navLink("$adminBase/servers", 'server', '服务器管理', 'admin.servers'),
            $navLink("$adminBase/nodes", 'globe', '节点监控', 'admin.nodes'),
            $navLink("$adminBase/groups", 'shield', '权限组管理', 'admin.groups'),
            $navLink("$adminBase/routing", 'route', '路由管理', 'admin.routing'),
        ],
        '订阅管理' => [
            $navLink("$adminBase/plans", 'tag', '套餐管理', 'admin.plans'),
            $navLink("$adminBase/orders", 'list', '订单管理', 'admin.orders'),
            $navLink("$adminBase/coupons", 'percent', '优惠券管理', 'admin.coupons'),
            $navLink("$adminBase/giftcards", 'gift', '礼品卡管理', 'admin.giftcards'),
            // Only a 分站 has a 进货余额 to owe 站长 for — on the main site this
            // route would just render an empty ledger, so it's not a matter of
            // canAccessAdminRoute() (站长 passes every route on 主站 unfiltered);
            // this link itself must not exist there.
            ...(tenant() ? [$navLink("$adminBase/wholesale", 'card', '进货流水', 'admin.wholesale')] : []),
        ],
        '分站管理' => [
            $navLink("$adminBase/tenants", 'globe', '分站管理', 'admin.tenants'),
        ],
        '用户管理' => [
            $navLink("$adminBase/users", 'users', '用户管理', 'admin.users'),
            $navLink("$adminBase/tickets", 'ticket', '工单管理', 'admin.tickets'),
            $navLink("$adminBase/invites", 'invite', '邀请管理', 'admin.invites'),
        ],
    ];

    // Hide sections this admin's role can't open (客服 sees a reduced menu);
    // drop any group left with no visible links.
    $navGroups = collect($navGroups)
        ->map(fn ($links) => array_values(array_filter($links, fn ($l) => $admin->canAccessAdminRoute($l['route']))))
        ->filter(fn ($links) => count($links) > 0)
        ->all();
@endphp
<nav class="flex-1 overflow-y-auto py-4 px-3 space-y-5 text-sm">
    <div>
        <a
            href="{{ $adminBase }}/"
            class="flex items-center gap-2.5 px-3 py-2 rounded-lg {{ request()->is(ltrim($adminBase, '/')) || request()->is(ltrim($adminBase, '/') . '/') ? 'bg-teal-50 dark:bg-teal-500/10 text-teal-700 dark:text-teal-400 font-medium' : 'text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800' }}"
        >
            @include('partials.icon', ['name' => 'home'])
            仪表盘
        </a>
    </div>
    @foreach ($navGroups as $groupLabel => $links)
        <div>
            <div class="px-3 mb-1.5 text-xs font-medium text-gray-400 dark:text-gray-500 uppercase tracking-wide">{{ $groupLabel }}</div>
            @foreach ($links as $link)
                <a
                    href="{{ $link['path'] }}"
                    class="flex items-center gap-2.5 px-3 py-2 rounded-lg {{ request()->is(ltrim($link['path'], '/') . '*') ? 'bg-teal-50 dark:bg-teal-500/10 text-teal-700 dark:text-teal-400 font-medium' : 'text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800' }}"
                >
                    @include('partials.icon', ['name' => $link['icon']])
                    {{ $link['label'] }}
                </a>
            @endforeach
        </div>
    @endforeach
</nav>

<div class="p-3 border-t border-gray-200 dark:border-gray-800 space-y-1">
    <a href="{{ $adminBase }}/profile" class="flex items-center gap-2.5 px-3 py-2 rounded-lg {{ request()->is(ltrim($adminBase, '/') . '/profile') ? 'bg-teal-50 dark:bg-teal-500/10 text-teal-700 dark:text-teal-400 font-medium' : 'text-gray-500 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-800' }} text-sm">
        @include('partials.icon', ['name' => 'user'])
        个人中心
    </a>
    <a href="/" class="flex items-center gap-2.5 px-3 py-2 rounded-lg text-gray-500 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-800 text-sm">
        @include('partials.icon', ['name' => 'home'])
        返回前台
    </a>
    <form method="POST" action="{{ route('admin.logout') }}">
        @csrf
        <button type="submit" class="w-full flex items-center gap-2.5 px-3 py-2 rounded-lg text-gray-500 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-800 text-sm">
            @include('partials.icon', ['name' => 'logout'])
            退出登录
        </button>
    </form>
</div>
