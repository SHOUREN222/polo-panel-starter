<?php

use App\Models\Order;
use App\Models\User;
use App\Services\SharedNodeService;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts.admin', ['title' => '用户管理'])] class extends Component
{
    use WithPagination;

    public string $successMessage = '';
    public string $error = '';
    public string $search = '';
    public string $statusFilter = ''; // '' | normal | banned | has_plan | has_balance

    // 调整余额
    public ?int $adjustUserId = null;
    public string $adjustAmount = '';
    public string $adjustNote = '';

    // 用户详情
    public ?int $detailUserId = null;

    // 管理员内部备注（详情面板内编辑）
    public string $noteDraft = '';

    // 代理设置（详情面板内编辑）
    public bool $agentEnabled = false;
    public string $agentDiscount = '';

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingStatusFilter(): void
    {
        $this->resetPage();
    }

    /**
     * Every write action on this page (ban / balance / reset / note) is blocked
     * for the 客服 (support) role — support may open 用户管理 to look, but never
     * mutate. Super admins, the 站长, and legacy roleless admins keep full
     * access (mirrors canAccessAdminRoute's "support restricted, everyone else
     * full" model). The route middleware lets support *reach* this component,
     * so the gate has to live in each server-side action — Livewire methods are
     * directly invocable, hiding the button alone isn't enough. Returns true
     * (and sets $error) when the caller is support and must be stopped.
     */
    private function denyWriteForSupport(): bool
    {
        if (auth()->user()->isSupport()) {
            $this->error = '客服无权执行此操作（用户页只读）';

            return true;
        }

        return false;
    }

    public function viewUser(int $userId): void
    {
        $this->detailUserId = $userId;
        $this->adjustUserId = null;
        $user = User::find($userId);
        $this->noteDraft = (string) ($user?->admin_note ?? '');
        $this->agentEnabled = (bool) ($user?->is_agent);
        $this->agentDiscount = (string) ($user?->agent_discount ?: '');
    }

    /**
     * Promote/demote a user to 代理 and set their 进货折扣. A reseller capability
     * (grants discounted card generation from balance), so it's a write action —
     * blocked for 客服, same as balance adjustment.
     */
    public function saveAgent(int $userId): void
    {
        $this->error = '';
        $this->successMessage = '';

        if ($this->denyWriteForSupport()) {
            return;
        }

        $this->validate(
            ['agentDiscount' => ['nullable', 'integer', 'min:0', 'max:90']],
            [],
            ['agentDiscount' => '进货折扣'],
        );

        $user = User::findOrFail($userId);
        $discount = $this->agentEnabled ? (int) ($this->agentDiscount ?: 0) : 0;

        $user->forceFill([
            'is_agent' => $this->agentEnabled,
            'agent_discount' => $discount,
        ])->save();

        $this->successMessage = $this->agentEnabled
            ? "已将 {$user->email} 设为代理（进货折扣 {$discount}% off）"
            : "已取消 {$user->email} 的代理身份";
    }

    /**
     * Save the admin-only internal note. Written via forceFill (admin_note is
     * intentionally not mass-assignable) so it can never be set from a user
     * form; only this admin action touches it.
     */
    public function saveAdminNote(): void
    {
        if ($this->denyWriteForSupport()) {
            return;
        }

        if (! $this->detailUserId) {
            return;
        }

        $this->validate(['noteDraft' => ['nullable', 'string', 'max:1000']], [], ['noteDraft' => '备注']);

        $user = User::findOrFail($this->detailUserId);
        $user->forceFill(['admin_note' => $this->noteDraft !== '' ? $this->noteDraft : null])->save();

        $this->successMessage = "已保存 {$user->email} 的内部备注";
    }

    /**
     * Grant / change / revoke a user's admin role. Only 超级管理员 may do this.
     * is_admin + admin_role are intentionally not mass-assignable, so this is
     * the single audited path that writes them (via forceFill). Guards against
     * changing your own role and against removing the last super admin — either
     * would risk locking everyone out of the panel.
     *
     * @param  string|null  $role  User::ROLE_SUPER, User::ROLE_SUPPORT, or null to revoke
     */
    public function setAdminRole(int $userId, ?string $role): void
    {
        $this->error = '';
        $this->successMessage = '';

        if (! auth()->user()->isSuperAdmin()) {
            $this->error = '只有超级管理员可以管理权限';

            return;
        }

        if ($role !== null && ! in_array($role, [User::ROLE_SUPER, User::ROLE_SUPPORT], true)) {
            $this->error = '未知的角色';

            return;
        }

        if ($userId === auth()->id()) {
            $this->error = '不能修改自己的管理员权限';

            return;
        }

        $user = User::findOrFail($userId);

        // The 站长 account is untouchable — no one may demote or revoke it.
        if ($user->isOwner()) {
            $this->error = '站长账号不可修改';

            return;
        }

        // The 超级管理员 tier is owner-only: only the 站长 may grant super, or
        // change/revoke anyone who currently holds super. Other super admins can
        // only manage the 客服 tier (promote normal↔客服, revoke 客服).
        $touchesSuperTier = $role === User::ROLE_SUPER || $user->isSuperAdmin();
        if ($touchesSuperTier && ! auth()->user()->isOwner()) {
            $this->error = '只有站长可以设置或变更超级管理员';

            return;
        }

        // Removing super from the last remaining super admin would strand the
        // panel with no one who can manage system/payment/nodes.
        $losingSuper = $user->isSuperAdmin() && $role !== User::ROLE_SUPER;
        if ($losingSuper && User::where('is_admin', true)->where('admin_role', User::ROLE_SUPER)->count() <= 1) {
            $this->error = '必须至少保留一个超级管理员';

            return;
        }

        $user->forceFill([
            'is_admin' => $role !== null,
            'admin_role' => $role,
        ])->save();

        $this->successMessage = match ($role) {
            User::ROLE_SUPER => "已将 {$user->email} 设为超级管理员",
            User::ROLE_SUPPORT => "已将 {$user->email} 设为客服",
            default => "已取消 {$user->email} 的管理员权限",
        };
    }

    public function closeDetail(): void
    {
        $this->detailUserId = null;
    }

    /**
     * Issue a fresh random password for a customer who can't get in — e.g. they
     * lost it and the reset email isn't reaching them (QQ/163 spam filtering).
     * The new password is shown to the admin once, to hand over; the customer
     * can change it themselves in 个人中心 afterwards. Admin accounts are
     * excluded, same as 封禁 — an admin's password shouldn't be resettable from
     * another admin's user list.
     */
    public function resetPassword(int $userId): void
    {
        if ($this->denyWriteForSupport()) {
            return;
        }

        $user = User::findOrFail($userId);

        if ($user->is_admin) {
            $this->error = '不能在这里重置管理员账号的密码';

            return;
        }

        $newPassword = Str::random(12);
        $user->update(['password' => $newPassword]); // 'password' => 'hashed' cast hashes it

        $this->successMessage = "已重置 {$user->email} 的密码，新密码：{$newPassword} —— 只显示这一次，请立即转交客户，并提醒其登录后到「个人中心」自行修改。";
    }

    public function resetSubscription(int $userId): void
    {
        if ($this->denyWriteForSupport()) {
            return;
        }

        $user = User::findOrFail($userId);
        $user->resetSubscriptionCredentials();
        $user->save();
        $this->successMessage = "已重置 {$user->email} 的订阅凭据（旧订阅链接立即失效）";
    }

    public function toggleBan(int $userId): void
    {
        $this->error = '';

        if ($this->denyWriteForSupport()) {
            return;
        }

        if ($userId === auth()->id()) {
            $this->error = '不能封禁自己';

            return;
        }

        $user = User::findOrFail($userId);

        // Admin accounts (站长/超管/客服) are never bannable through this button —
        // banning an admin would be a lockout/privilege weapon, not moderation.
        if ($user->is_admin) {
            $this->error = '不能封禁管理员账号';

            return;
        }

        $user->update(['banned' => ! $user->banned]);

        $this->successMessage = $user->banned ? '已封禁该用户' : '已解封该用户';
    }

    public function startAdjust(int $userId): void
    {
        $this->adjustUserId = $userId;
        $this->adjustAmount = '';
        $this->adjustNote = '';
        $this->error = '';
        $this->successMessage = '';
    }

    public function cancelAdjust(): void
    {
        $this->adjustUserId = null;
        $this->adjustAmount = '';
        $this->adjustNote = '';
    }

    /**
     * Manually add/deduct a user's balance (comp / manual top-up) — 超管/站长
     * only, never 客服 (balance is money-equivalent store credit). A note is
     * required so there's a reason on record. Balance never goes below zero
     * and is store-credit only (can't be withdrawn).
     */
    public function saveAdjust(): void
    {
        if ($this->denyWriteForSupport()) {
            return;
        }

        $this->validate([
            'adjustAmount' => ['required', 'numeric', 'not_in:0'],
            'adjustNote' => ['required', 'string', 'max:255'],
        ], [], ['adjustAmount' => '金额', 'adjustNote' => '备注']);

        $user = User::findOrFail($this->adjustUserId);
        $cents = (int) round(((float) $this->adjustAmount) * 100);
        $applied = $user->changeBalance($cents, \App\Models\BalanceTransaction::TYPE_ADJUST_ADMIN, $this->adjustNote, auth()->id());

        $sign = $applied >= 0 ? '+' : '−';
        $this->successMessage = "已调整 {$user->email} 余额 {$sign}¥".number_format(abs($applied) / 100, 2)."（备注：{$this->adjustNote}），当前余额 ¥".number_format($user->fresh()->balance / 100, 2);
        $this->cancelAdjust();
    }

    public function with(): array
    {
        $query = User::withCount(['orders', 'subscriptionInstances'])->with('plan:id,name')->latest();

        if ($this->search !== '') {
            $query->where(function ($q) {
                $q->where('email', 'like', "%{$this->search}%")
                    ->orWhere('name', 'like', "%{$this->search}%");
            });
        }

        match ($this->statusFilter) {
            'normal' => $query->where('banned', false),
            'banned' => $query->where('banned', true),
            'has_plan' => $query->where(fn ($q) => $q->where('plan_expired_at', '>', now())->orHas('subscriptionInstances')),
            'has_balance' => $query->where('balance', '>', 0),
            default => null,
        };

        $detail = null;
        if ($this->detailUserId) {
            $u = User::with(['plan:id,name,transfer_enable', 'referredBy:id,email'])->find($this->detailUserId);
            if ($u) {
                $detail = [
                    'user' => $u,
                    'transactions' => $u->balanceTransactions()->latest()->limit(15)->get(),
                    'instances' => $u->subscriptionInstances()->latest()->get(),
                    'orders' => Order::with('plan:id,name')->where('user_id', $u->id)->latest()->limit(10)->get(),
                    'sub_url' => $u->subscriptionUrl(),
                    'shared_used' => $u->hasActiveSharedPlan() ? app(SharedNodeService::class)->sharedTrafficUsedBytes($u) : 0,
                    'shared_quota' => $u->hasActiveSharedPlan() ? app(SharedNodeService::class)->effectiveSharedQuotaBytes($u) : 0,
                    'referrals' => $u->referrals()->count(),
                    'invite_codes' => $u->inviteCodes()->count(),
                    'commission_total' => (int) $u->commissions()->sum('amount'),
                    'tickets' => $u->tickets()->latest()->limit(5)->get(),
                ];
            }
        }

        return [
            'users' => $query->paginate(20),
            'detail' => $detail,
            'stats' => $this->stats(),
        ];
    }

    /**
     * Top-of-page overview metrics + a 14-day new-user trend, for at-a-glance
     * operations at scale.
     */
    private function stats(): array
    {
        $withPlan = User::where(fn ($q) => $q->where('plan_expired_at', '>', now())->orHas('subscriptionInstances'))->count();

        // 14-day daily registrations (oldest → newest)
        $counts = User::where('created_at', '>=', now()->subDays(13)->startOfDay())
            ->get(['created_at'])
            ->groupBy(fn ($u) => $u->created_at->toDateString())
            ->map->count();

        $trend = [];
        for ($i = 13; $i >= 0; $i--) {
            $day = now()->subDays($i);
            $trend[] = ['label' => $day->format('m-d'), 'count' => (int) ($counts[$day->toDateString()] ?? 0)];
        }

        return [
            'total' => User::count(),
            'new_month' => User::where('created_at', '>=', now()->startOfMonth())->count(),
            'with_plan' => $withPlan,
            'with_balance' => User::where('balance', '>', 0)->count(),
            'total_balance' => (int) User::sum('balance'),
            'banned' => User::where('banned', true)->count(),
            'trend' => $trend,
            'trend_max' => max(1, collect($trend)->max('count')),
        ];
    }
};
?>

<div class="space-y-6">
    <h1 class="text-xl font-semibold text-gray-900 dark:text-gray-100">用户管理</h1>

    {{-- 用户统计面板 --}}
    <div class="grid grid-cols-2 md:grid-cols-6 gap-3">
        @php
            $cards = [
                ['总用户', $stats['total'], 'text-gray-900 dark:text-gray-100'],
                ['本月新增', $stats['new_month'], 'text-green-600 dark:text-green-400'],
                ['有效订阅', $stats['with_plan'], 'text-teal-600 dark:text-teal-400'],
                ['有余额用户', $stats['with_balance'], 'text-gray-900 dark:text-gray-100'],
                ['余额总额', '¥'.number_format($stats['total_balance'] / 100, 2), 'text-teal-600 dark:text-teal-400'],
                ['已封禁', $stats['banned'], 'text-red-500'],
            ];
        @endphp
        @foreach ($cards as [$label, $val, $color])
            <div class="border border-gray-200 dark:border-gray-800 rounded-xl px-4 py-3 bg-white dark:bg-gray-900">
                <div class="text-xs text-gray-500 dark:text-gray-400">{{ $label }}</div>
                <div class="text-lg font-semibold {{ $color }} mt-0.5">{{ $val }}</div>
            </div>
        @endforeach
    </div>

    {{-- 近 14 天新增用户趋势 --}}
    <div class="border border-gray-200 dark:border-gray-800 rounded-xl p-4 bg-white dark:bg-gray-900">
        <div class="text-xs text-gray-500 dark:text-gray-400 mb-2">近 14 天新增用户</div>
        <div class="flex items-end gap-1.5 h-24">
            @foreach ($stats['trend'] as $d)
                <div class="flex-1 flex flex-col items-center justify-end h-full group" title="{{ $d['label'] }}：{{ $d['count'] }} 人">
                    <span class="text-[10px] text-gray-400 dark:text-gray-500 mb-0.5">{{ $d['count'] > 0 ? $d['count'] : '' }}</span>
                    <div class="w-full rounded-t bg-teal-500/70 dark:bg-teal-500/50 group-hover:bg-teal-500" style="height: {{ max(2, round($d['count'] / $stats['trend_max'] * 100)) }}%"></div>
                    <span class="text-[10px] text-gray-400 dark:text-gray-500 mt-1">{{ substr($d['label'], -2) }}</span>
                </div>
            @endforeach
        </div>
    </div>

    @if ($successMessage)
        <div class="text-sm text-green-700 dark:text-green-400 bg-green-50 dark:bg-green-500/10 border border-green-100 dark:border-green-500/20 rounded-lg p-3">{{ $successMessage }}</div>
    @endif
    @if ($error)
        <div class="text-sm text-red-600 dark:text-red-400 bg-red-50 dark:bg-red-500/10 border border-red-100 dark:border-red-500/20 rounded-lg p-3">{{ $error }}</div>
    @endif

    <div class="flex flex-wrap items-center gap-2">
        <input
            type="text"
            wire:model.live.debounce.400ms="search"
            placeholder="搜索邮箱或昵称..."
            class="max-w-xs flex-1 min-w-48 border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm"
        >
        <select wire:model.live="statusFilter" class="text-sm border border-gray-300 dark:border-gray-700 dark:bg-gray-800 text-gray-700 dark:text-gray-300 rounded-lg px-3 py-2">
            <option value="">全部用户</option>
            <option value="normal">正常</option>
            <option value="banned">已封禁</option>
            <option value="has_plan">有订阅</option>
            <option value="has_balance">有余额</option>
        </select>
    </div>

    @if ($detail)
        @php $du = $detail['user']; @endphp
        <div class="border border-gray-200 dark:border-gray-800 rounded-xl p-5 bg-white dark:bg-gray-900 space-y-4">
            <div class="flex items-start justify-between">
                <div>
                    <h2 class="font-medium text-gray-900 dark:text-gray-100">
                        {{ $du->email }} <span class="text-gray-400 text-sm">/ {{ $du->name }}</span>
                        @if ($du->email_verified_at)
                            <span class="text-xs bg-green-50 dark:bg-green-500/10 text-green-700 dark:text-green-400 rounded-full px-2 py-0.5 ml-1">邮箱已验证</span>
                        @else
                            <span class="text-xs bg-gray-100 dark:bg-gray-800 text-gray-500 rounded-full px-2 py-0.5 ml-1">邮箱未验证</span>
                        @endif
                        @if ($du->telegram_id)
                            <span class="text-xs bg-blue-50 dark:bg-blue-500/10 text-blue-700 dark:text-blue-400 rounded-full px-2 py-0.5">TG已绑{{ $du->telegram_username ? ' @'.$du->telegram_username : '' }}</span>
                        @else
                            <span class="text-xs bg-gray-100 dark:bg-gray-800 text-gray-500 rounded-full px-2 py-0.5">TG未绑</span>
                        @endif
                    </h2>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                        余额 <span class="text-teal-600 dark:text-teal-400 font-medium">¥{{ number_format($du->balance / 100, 2) }}</span>
                        · 注册 {{ $du->created_at->format('Y-m-d') }}
                        · {{ $du->banned ? '已封禁' : '正常' }}
                        · 邀请 {{ $detail['referrals'] }} 人 · 佣金累计 ¥{{ number_format($detail['commission_total'] / 100, 2) }} · 邀请码 {{ $detail['invite_codes'] }} 张
                    </p>
                </div>
                <div class="flex gap-2 shrink-0">
                    @if (! auth()->user()->isSupport())
                        <button wire:click="startAdjust({{ $du->id }})" class="text-xs border border-gray-300 dark:border-gray-700 text-gray-700 dark:text-gray-300 rounded-lg px-3 py-1.5">调余额</button>
                        <button wire:click="resetSubscription({{ $du->id }})" wire:confirm="重置后该用户旧订阅链接立即失效，确定？" class="text-xs border border-gray-300 dark:border-gray-700 text-gray-700 dark:text-gray-300 rounded-lg px-3 py-1.5">重置订阅</button>
                        @if (! $du->is_admin)
                            <button wire:click="resetPassword({{ $du->id }})" wire:confirm="将为 {{ $du->email }} 生成一个新密码，旧密码立即失效。新密码只显示一次，需要你转交给客户。确定？" class="text-xs border border-gray-300 dark:border-gray-700 text-gray-700 dark:text-gray-300 rounded-lg px-3 py-1.5">重置密码</button>
                            @if ($du->banned)
                                <button wire:click="toggleBan({{ $du->id }})" wire:confirm="确定解封 {{ $du->email }}？" class="text-xs border border-green-300 dark:border-green-800 text-green-700 dark:text-green-400 rounded-lg px-3 py-1.5">解封</button>
                            @else
                                <button wire:click="toggleBan({{ $du->id }})" wire:confirm="封禁后该用户无法登录、订阅立即失效，确定？" class="text-xs border border-red-300 dark:border-red-800 text-red-600 dark:text-red-400 rounded-lg px-3 py-1.5">封禁</button>
                            @endif
                        @endif
                    @endif
                    <button wire:click="closeDetail" class="text-xs text-gray-400 hover:text-gray-600">收起</button>
                </div>
            </div>

            {{-- 订阅链接 --}}
            <div x-data="{ copied: false }" class="flex items-center gap-2">
                <span class="text-xs text-gray-500 dark:text-gray-400 shrink-0">订阅链接</span>
                <code class="flex-1 text-xs bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg px-3 py-1.5 font-mono text-gray-600 dark:text-gray-400 truncate">{{ $detail['sub_url'] }}</code>
                <button type="button" @click="navigator.clipboard.writeText('{{ $detail['sub_url'] }}'); copied = true; setTimeout(() => copied = false, 1500)" class="shrink-0 text-xs border border-gray-300 dark:border-gray-700 text-gray-700 dark:text-gray-300 rounded-lg px-3 py-1.5"><span x-show="!copied">复制</span><span x-show="copied" x-cloak>已复制</span></button>
            </div>

            {{-- 已验证邮箱 / 已绑TG 的详细资料 --}}
            @if ($du->email_verified_at || $du->telegram_id)
                <div class="flex flex-wrap gap-x-8 gap-y-1 bg-gray-50 dark:bg-gray-800/50 border border-gray-100 dark:border-gray-800 rounded-lg px-3 py-2 text-xs">
                    @if ($du->email_verified_at)
                        <div>
                            <span class="text-gray-500 dark:text-gray-400">邮箱</span>
                            <span class="text-gray-800 dark:text-gray-200 font-medium ml-1">{{ $du->email }}</span>
                            <span class="text-green-600 dark:text-green-400 ml-1">已验证</span>
                            <span class="text-gray-400 dark:text-gray-500 ml-1">· {{ $du->email_verified_at->format('Y-m-d H:i') }}</span>
                        </div>
                    @endif
                    @if ($du->telegram_id)
                        <div>
                            <span class="text-gray-500 dark:text-gray-400">Telegram</span>
                            <span class="text-gray-800 dark:text-gray-200 font-medium ml-1">{{ $du->telegram_username ? '@'.$du->telegram_username : '（未设用户名）' }}</span>
                            <span class="text-gray-400 dark:text-gray-500 ml-1 font-mono">· ID {{ $du->telegram_id }}</span>
                        </div>
                    @endif
                </div>
            @endif

            {{-- 账号信息：上级 / 最后登录 / UUID --}}
            <div class="flex flex-wrap gap-x-8 gap-y-1 text-xs text-gray-500 dark:text-gray-400">
                <span>上级邀请人 <span class="text-gray-700 dark:text-gray-300">{{ $du->referredBy?->email ?? '无' }}</span></span>
                <span>最后登录 <span class="text-gray-700 dark:text-gray-300">{{ $du->last_login_at ? $du->last_login_at->format('Y-m-d H:i') : '从未登录' }}</span>@if ($du->last_login_ip)<span class="font-mono ml-1">{{ $du->last_login_ip }}</span>@endif</span>
                <span>UUID <span class="font-mono text-gray-600 dark:text-gray-400">{{ $du->uuid }}</span></span>
            </div>

            {{-- 管理员权限（角色分级）：仅超级管理员可编辑，超管这一层只有站长能设 --}}
            @php $actorSuper = auth()->user()->isSuperAdmin(); $actorOwner = auth()->user()->isOwner(); @endphp
            @if ($du->is_admin || $actorSuper)
                <div class="flex items-center gap-2 flex-wrap text-xs bg-gray-50 dark:bg-gray-800/50 border border-gray-100 dark:border-gray-800 rounded-lg px-3 py-2">
                    <span class="text-gray-500 dark:text-gray-400">管理员权限</span>
                    <span class="rounded-full px-2 py-0.5 font-medium {{ $du->isOwner() ? 'bg-amber-50 dark:bg-amber-500/10 text-amber-700 dark:text-amber-400' : ($du->isSuperAdmin() ? 'bg-purple-50 dark:bg-purple-500/10 text-purple-700 dark:text-purple-400' : ($du->isSupport() ? 'bg-blue-50 dark:bg-blue-500/10 text-blue-700 dark:text-blue-400' : 'bg-gray-100 dark:bg-gray-800 text-gray-500')) }}">{{ $du->adminRoleLabel() }}</span>
                    @if ($du->isOwner())
                        <span class="text-gray-400 dark:text-gray-500">（站长账号，不可修改）</span>
                    @elseif ($actorSuper && $du->id !== auth()->id())
                        {{-- 设为客服：普通用户→客服（任意超管可），或超管→客服（仅站长可降级） --}}
                        @if (! $du->isSupport() && (! $du->isSuperAdmin() || $actorOwner))
                            <button wire:click="setAdminRole({{ $du->id }}, 'support')" class="border border-blue-300 dark:border-blue-800 text-blue-700 dark:text-blue-400 rounded-lg px-2.5 py-1">设为客服</button>
                        @endif
                        {{-- 设为超管：仅站长可 --}}
                        @if (! $du->isSuperAdmin() && $actorOwner)
                            <button wire:click="setAdminRole({{ $du->id }}, 'super')" wire:confirm="超级管理员拥有全部后台权限（支付/系统/节点全放开），确定？" class="border border-purple-300 dark:border-purple-800 text-purple-700 dark:text-purple-400 rounded-lg px-2.5 py-1">设为超管</button>
                        @endif
                        {{-- 取消管理员：客服（任意超管可），或超管（仅站长可） --}}
                        @if ($du->is_admin && (! $du->isSuperAdmin() || $actorOwner))
                            <button wire:click="setAdminRole({{ $du->id }}, null)" wire:confirm="确定取消 {{ $du->email }} 的管理员权限？" class="border border-red-300 dark:border-red-800 text-red-600 dark:text-red-400 rounded-lg px-2.5 py-1">取消管理员</button>
                        @endif
                        @if (! $actorOwner)
                            <span class="text-gray-400 dark:text-gray-500">超管的设置/变更需站长操作，你可管理客服</span>
                        @else
                            <span class="text-gray-400 dark:text-gray-500">客服只能处理工单、查看用户/订单/邀请（用户页只读）；封禁、调余额、重置订阅、支付、系统、节点都碰不到</span>
                        @endif
                    @elseif ($du->id === auth()->id())
                        <span class="text-gray-400 dark:text-gray-500">（不能修改自己的权限）</span>
                    @endif
                </div>
            @endif

            {{-- 代理设置：设为代理 + 进货折扣（客服只读，不显示控件） --}}
            @if (! auth()->user()->isSupport())
                <div class="flex items-center gap-3 flex-wrap text-xs bg-gray-50 dark:bg-gray-800/50 border border-gray-100 dark:border-gray-800 rounded-lg px-3 py-2">
                    <span class="text-gray-500 dark:text-gray-400">代理</span>
                    <label class="flex items-center gap-1.5 text-gray-700 dark:text-gray-300">
                        <input type="checkbox" wire:model="agentEnabled"> 设为代理
                    </label>
                    <span class="flex items-center gap-1.5 text-gray-500 dark:text-gray-400">
                        进货折扣
                        <input type="number" min="0" max="90" wire:model="agentDiscount" placeholder="0" class="w-16 border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-2 py-1"> % off
                    </span>
                    <button wire:click="saveAgent({{ $du->id }})" class="border border-teal-300 dark:border-teal-800 text-teal-700 dark:text-teal-400 rounded-lg px-2.5 py-1">保存</button>
                    @if ($du->is_agent)
                        <span class="rounded-full px-2 py-0.5 bg-teal-50 dark:bg-teal-500/10 text-teal-700 dark:text-teal-400">当前：代理 · {{ (int) $du->agent_discount }}% off</span>
                    @endif
                    @error('agentDiscount')<span class="text-red-500">{{ $message }}</span>@enderror
                </div>
            @endif

            {{-- 管理员内部备注（仅后台可见，仅超管可编辑） --}}
            @if (! auth()->user()->isSupport())
                <div>
                    <div class="flex items-center justify-between mb-1">
                        <span class="text-xs font-medium text-gray-500 dark:text-gray-400">管理员内部备注（仅后台可见）</span>
                        <button wire:click="saveAdminNote" class="text-xs border border-gray-300 dark:border-gray-700 text-gray-700 dark:text-gray-300 rounded-lg px-3 py-1">保存备注</button>
                    </div>
                    <textarea wire:model="noteDraft" rows="2" placeholder="给这个客户加内部备注，如 VIP、投诉过、退款争议…（客户看不到）" class="w-full text-xs bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg px-3 py-2 text-gray-700 dark:text-gray-300 focus:outline-none focus:ring-1 focus:ring-teal-500"></textarea>
                    @error('noteDraft')<span class="text-xs text-red-500">{{ $message }}</span>@enderror
                </div>
            @elseif ($du->admin_note)
                {{-- 客服只读：能看到已有备注但不能改 --}}
                <div>
                    <span class="text-xs font-medium text-gray-500 dark:text-gray-400">管理员内部备注（只读）</span>
                    <p class="mt-1 text-xs text-gray-600 dark:text-gray-400 whitespace-pre-line bg-gray-50 dark:bg-gray-800/50 border border-gray-100 dark:border-gray-800 rounded-lg px-3 py-2">{{ $du->admin_note }}</p>
                </div>
            @endif

            <div class="grid md:grid-cols-3 gap-4">
                {{-- 套餐 & 流量 & ISP线路 --}}
                <div>
                    <div class="text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">套餐 / 流量</div>
                    <div class="space-y-1 text-xs">
                        @if ($du->hasActiveSharedPlan())
                            <div class="text-gray-600 dark:text-gray-400">{{ $du->plan->name ?? '共享' }} · 到{{ $du->plan_expired_at->format('m-d') }}
                                <span class="text-gray-400">· 用 {{ number_format($detail['shared_used'] / 1073741824, 1) }}/{{ number_format($detail['shared_quota'] / 1073741824, 0) }}G</span>
                            </div>
                        @endif
                        @forelse ($detail['instances'] as $inst)
                            <div class="text-gray-600 dark:text-gray-400 font-mono">{{ $inst->meta['country'] ?? '?' }}·{{ $inst->meta['ip'] ?? '-' }}·{{ $inst->status }}·到{{ $inst->expired_at?->format('m-d') }}
                                <span class="text-gray-400">· {{ number_format(($inst->u + $inst->d) / 1073741824, 1) }}/{{ number_format($inst->effectiveQuotaBytes() / 1073741824, 0) }}G</span>
                            </div>
                        @empty
                            @if (! $du->hasActiveSharedPlan())<div class="text-gray-400 dark:text-gray-600">无订阅</div>@endif
                        @endforelse
                    </div>
                </div>
                {{-- 余额流水 --}}
                <div>
                    <div class="text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">余额流水（近15条）</div>
                    <div class="space-y-1 text-xs max-h-40 overflow-y-auto">
                        @forelse ($detail['transactions'] as $t)
                            <div class="flex justify-between gap-2">
                                <span class="text-gray-600 dark:text-gray-400">{{ $t->created_at->format('m-d H:i') }} {{ $t->typeLabel() }}@if($t->note)<span class="text-gray-400">·{{ \Illuminate\Support\Str::limit($t->note, 12) }}</span>@endif</span>
                                <span class="{{ $t->amount >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-500' }} shrink-0">{{ $t->amount >= 0 ? '+' : '−' }}¥{{ number_format(abs($t->amount) / 100, 2) }}</span>
                            </div>
                        @empty
                            <div class="text-gray-400 dark:text-gray-600">无</div>
                        @endforelse
                    </div>
                </div>
                {{-- 订单 --}}
                <div>
                    <div class="text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">近期订单（{{ $detail['orders']->count() }}）</div>
                    @php $periodLabels = ['month' => '月付', 'quarter' => '季付', 'half_year' => '半年', 'year' => '年付', 'onetime' => '一次性']; @endphp
                    <div class="space-y-1.5 text-xs max-h-56 overflow-y-auto">
                        @forelse ($detail['orders'] as $o)
                            <div class="border border-gray-100 dark:border-gray-800 rounded-lg px-2 py-1.5 space-y-0.5">
                                <div class="flex justify-between gap-2">
                                    <span class="text-gray-700 dark:text-gray-300 truncate">{{ $o->typeLabel() }} · {{ $o->plan->name ?? '—' }}@if ($o->period) · {{ $periodLabels[$o->period] ?? $o->period }}@endif</span>
                                    <span class="shrink-0 {{ $o->status === 'paid' ? 'text-green-600 dark:text-green-400' : ($o->status === 'pending' ? 'text-yellow-600 dark:text-yellow-400' : 'text-gray-400') }}">{{ $o->status === 'paid' ? '已付' : ($o->status === 'pending' ? '待付' : '取消') }}</span>
                                </div>
                                <div class="flex justify-between gap-2 text-gray-400 dark:text-gray-500">
                                    <span class="font-mono truncate">{{ $o->trade_no }}</span>
                                    <span class="shrink-0 text-gray-600 dark:text-gray-400">¥{{ number_format($o->total_amount / 100, 2) }}</span>
                                </div>
                                @if ($o->balance_amount > 0 || $o->discount_amount > 0 || $o->balance_credit_amount > 0)
                                    <div class="text-gray-400 dark:text-gray-500">
                                        @if ($o->balance_amount > 0)余额抵 ¥{{ number_format($o->balance_amount / 100, 2) }}　@endif
                                        @if ($o->discount_amount > 0)优惠券 -¥{{ number_format($o->discount_amount / 100, 2) }}　@endif
                                        @if ($o->balance_credit_amount > 0)流量折抵 ¥{{ number_format($o->balance_credit_amount / 100, 2) }}@endif
                                    </div>
                                @endif
                                <div class="text-gray-400 dark:text-gray-500">{{ $o->status === 'paid' ? '支付于 '.($o->paid_at?->format('Y-m-d H:i') ?? '-') : '下单于 '.$o->created_at->format('Y-m-d H:i') }}</div>
                            </div>
                        @empty
                            <div class="text-gray-400 dark:text-gray-600">无</div>
                        @endforelse
                    </div>
                </div>
            </div>

            {{-- 工单 --}}
            <div>
                <div class="text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">工单（近5条）</div>
                <div class="space-y-1 text-xs">
                    @forelse ($detail['tickets'] as $tk)
                        <div class="flex justify-between gap-2">
                            <span class="text-gray-600 dark:text-gray-400">{{ $tk->created_at->format('m-d') }} · {{ \Illuminate\Support\Str::limit($tk->subject ?? $tk->title ?? '工单', 30) }}</span>
                            <span class="shrink-0 text-gray-400">{{ $tk->status }}</span>
                        </div>
                    @empty
                        <div class="text-gray-400 dark:text-gray-600">无</div>
                    @endforelse
                </div>
            </div>
        </div>
    @endif

    <table class="w-full text-sm bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-xl overflow-hidden">
        <thead class="bg-gray-50 dark:bg-gray-800/60 text-gray-500 dark:text-gray-400 text-xs">
            <tr>
                <th class="text-left px-4 py-2">邮箱</th>
                <th class="text-left px-4 py-2">昵称</th>
                <th class="text-left px-4 py-2">余额</th>
                <th class="text-left px-4 py-2">当前套餐 / 到期</th>
                <th class="text-left px-4 py-2">订单</th>
                <th class="text-left px-4 py-2">注册</th>
                <th class="text-left px-4 py-2">状态</th>
                <th class="text-left px-4 py-2"></th>
            </tr>
        </thead>
        <tbody>
            @foreach ($users as $user)
                <tr class="border-t border-gray-100 dark:border-gray-800">
                    <td class="px-4 py-2 text-gray-700 dark:text-gray-300">{{ $user->email }}</td>
                    <td class="px-4 py-2 text-gray-500 dark:text-gray-400">{{ $user->name }}</td>
                    <td class="px-4 py-2 {{ $user->balance > 0 ? 'text-teal-600 dark:text-teal-400' : 'text-gray-500 dark:text-gray-400' }}">¥{{ number_format($user->balance / 100, 2) }}</td>
                    <td class="px-4 py-2 text-xs text-gray-500 dark:text-gray-400">
                        @if ($user->hasActiveSharedPlan())
                            {{ $user->plan->name ?? '共享套餐' }} <span class="text-gray-400">· 到 {{ $user->plan_expired_at->format('m-d') }}</span>
                        @endif
                        @if ($user->subscription_instances_count > 0)
                            <span class="text-gray-400">{{ $user->hasActiveSharedPlan() ? '· ' : '' }}{{ $user->subscription_instances_count }} 条ISP</span>
                        @endif
                        @if (! $user->hasActiveSharedPlan() && $user->subscription_instances_count === 0)
                            <span class="text-gray-400 dark:text-gray-600">-</span>
                        @endif
                    </td>
                    <td class="px-4 py-2 text-gray-500 dark:text-gray-400">{{ $user->orders_count }}</td>
                    <td class="px-4 py-2 text-xs text-gray-500 dark:text-gray-400">{{ $user->created_at->format('Y-m-d') }}</td>
                    <td class="px-4 py-2 space-x-1">
                        @if ($user->isOwner())
                            <span class="text-xs bg-amber-50 dark:bg-amber-500/10 text-amber-700 dark:text-amber-400 rounded-full px-2 py-1">站长</span>
                        @elseif ($user->isSuperAdmin())
                            <span class="text-xs bg-purple-50 dark:bg-purple-500/10 text-purple-700 dark:text-purple-400 rounded-full px-2 py-1">超管</span>
                        @elseif ($user->isSupport())
                            <span class="text-xs bg-blue-50 dark:bg-blue-500/10 text-blue-700 dark:text-blue-400 rounded-full px-2 py-1">客服</span>
                        @endif
                        @if ($user->banned)
                            <span class="text-xs bg-red-50 dark:bg-red-500/10 text-red-700 dark:text-red-400 rounded-full px-2 py-1">已封禁</span>
                        @else
                            <span class="text-xs bg-green-50 dark:bg-green-500/10 text-green-700 dark:text-green-400 rounded-full px-2 py-1">正常</span>
                        @endif
                    </td>
                    <td class="px-4 py-2 text-right space-x-2 whitespace-nowrap">
                        <button wire:click="viewUser({{ $user->id }})" class="text-xs text-teal-600 dark:text-teal-400 hover:underline">详情</button>
                        @if (! auth()->user()->isSupport())
                            <button wire:click="startAdjust({{ $user->id }})" class="text-xs text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-100">调余额</button>
                            @if (! $user->is_admin && $user->id !== auth()->id())
                                <button
                                    wire:click="toggleBan({{ $user->id }})"
                                    wire:confirm="确定要{{ $user->banned ? '解封' : '封禁' }}这个用户？"
                                    class="text-xs {{ $user->banned ? 'text-green-600 hover:text-green-800' : 'text-red-600 hover:text-red-800' }}"
                                >
                                    {{ $user->banned ? '解封' : '封禁' }}
                                </button>
                            @endif
                        @endif
                    </td>
                </tr>
                @if ($adjustUserId === $user->id)
                    <tr class="bg-gray-50 dark:bg-gray-800/40 border-t border-gray-100 dark:border-gray-800">
                        <td colspan="8" class="px-4 py-3">
                            <div class="flex flex-wrap items-end gap-3">
                                <div>
                                    <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">金额（元，正=加 / 负=扣）</label>
                                    <input type="number" step="0.01" wire:model="adjustAmount" placeholder="如 20 或 -5" class="w-32 border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-2 py-1.5 text-sm">
                                    @error('adjustAmount') <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p> @enderror
                                </div>
                                <div class="flex-1 min-w-48">
                                    <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">备注（必填，记录原因）</label>
                                    <input type="text" wire:model="adjustNote" placeholder="如：客服补偿 / 手动充值" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-2 py-1.5 text-sm">
                                    @error('adjustNote') <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p> @enderror
                                </div>
                                <button wire:click="saveAdjust" class="text-xs bg-gray-900 dark:bg-gray-100 text-white dark:text-gray-900 rounded-lg px-3 py-1.5">确认</button>
                                <button wire:click="cancelAdjust" class="text-xs border border-gray-300 dark:border-gray-700 text-gray-700 dark:text-gray-300 rounded-lg px-3 py-1.5">取消</button>
                            </div>
                        </td>
                    </tr>
                @endif
            @endforeach
        </tbody>
    </table>

    {{ $users->links() }}
</div>
