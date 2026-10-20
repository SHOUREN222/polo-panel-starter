<?php

use App\Models\Tenant;
use App\Models\User;
use App\Support\SiteUrl;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Stancl\Tenancy\Database\Models\Domain;

new #[Layout('layouts.admin', ['title' => '分站管理'])] class extends Component
{
    public string $subdomain = '';
    public string $adminName = '';
    public string $adminEmail = '';
    public string $adminPassword = '';
    public string $agentDiscount = '20';
    public string $topupAmount = '';
    public string $successMessage = '';
    public string $error = '';

    /** Keyed by tenant id — the discount edit control's pending value, one per row. */
    public array $discountEdits = [];

    public function mount(): void
    {
        foreach (Tenant::all() as $tenant) {
            $this->discountEdits[$tenant->id] = (string) ($tenant->agent_discount ?? 0);
        }
    }

    /** Managing 分站 is platform-level — 超级管理员 only. */
    private function denyIfNotSuper(): bool
    {
        if (! auth()->user()->isSuperAdmin()) {
            $this->error = '只有超级管理员可以管理分站';

            return true;
        }

        return false;
    }

    /**
     * stancl/tenancy's own delete cleanup doesn't always remove the physical
     * SQLite file (seen in production: a 分站 got deleted, then recreating
     * the same id failed with TenantDatabaseAlreadyExistsException because
     * the file was still on disk). Belt-and-suspenders on both ends: called
     * after delete() to actually clear the file, and again defensively
     * before create() in case an earlier delete left one behind anyway —
     * safe either time, since getting past the duplicate-id/domain checks
     * above already proves no live tenant owns this id.
     */
    private function purgeStaleDatabaseFile(string $id): void
    {
        $base = database_path(config('tenancy.database.prefix').$id.config('tenancy.database.suffix'));

        foreach ([$base, $base.'-wal', $base.'-shm'] as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    public function create(): void
    {
        $this->successMessage = '';
        $this->error = '';

        if ($this->denyIfNotSuper()) {
            return;
        }

        if (! SiteUrl::parentDomain()) {
            $this->error = '请先在 .env 配置 TENANCY_PARENT_DOMAIN';

            return;
        }

        $this->subdomain = strtolower(trim($this->subdomain));

        $this->validate([
            // a single subdomain label: letters/digits/hyphen, no leading/trailing hyphen
            'subdomain' => ['required', 'regex:/^[a-z0-9]([a-z0-9-]{0,30}[a-z0-9])?$/'],
            'adminName' => ['required', 'string', 'max:255'],
            'adminEmail' => ['required', 'email'],
            'adminPassword' => ['required', 'string', Password::min(8)],
            'agentDiscount' => ['required', 'integer', 'min:0', 'max:90'],
        ], [], [
            'subdomain' => '子域', 'adminName' => '管理员名称', 'adminEmail' => '管理员邮箱',
            'adminPassword' => '管理员密码', 'agentDiscount' => '批发折扣',
        ]);

        if (Tenant::find($this->subdomain) || Domain::where('domain', $this->subdomain)->exists()) {
            $this->error = '这个子域已被占用';

            return;
        }

        // Reject a label that would shadow one of the platform's own domains
        // For example, subdomain "app" must not shadow the central app host.
        $reservedLabels = collect(config('tenancy.central_domains', []))
            ->map(fn ($domain) => strtolower(explode('.', $domain)[0]))
            ->all();

        if (in_array($this->subdomain, $reservedLabels, true)) {
            $this->error = '这个子域与平台自身域名冲突，换一个';

            return;
        }

        // Stable per-tenant ordinal for the node protocol's global user ids
        // (see App\Support\NodeUserId) — central is 0, tenants are 1,2,3…
        // Never reused, even if a tenant with a high ordinal is later deleted.
        $ordinal = Tenant::nextNodeOrdinal();

        // A previous delete of this same id may have left its SQLite file on
        // disk (see purgeStaleDatabaseFile()) — clear it first so stancl's
        // CreateDatabase doesn't refuse to proceed.
        $this->purgeStaleDatabaseFile($this->subdomain);

        // stancl creates the tenant's SQLite DB + runs the tenant migrations.
        $tenant = Tenant::create([
            'id' => $this->subdomain,
            'agent_discount' => (int) $this->agentDiscount, // stored in the tenant `data` JSON
            'node_ordinal' => $ordinal,
        ]);
        $tenant->domains()->create(['domain' => $this->subdomain]);

        // Seed the 分站's own admin (代理管理员) inside the tenant DB.
        $name = $this->adminName;
        $email = strtolower(trim($this->adminEmail));
        $password = $this->adminPassword;
        $tenant->run(function () use ($name, $email, $password) {
            $user = new User();
            $user->forceFill([
                'name' => $name,
                'email' => $email,
                'password' => $password,   // hashed by the model cast
                'is_admin' => true,
                'admin_role' => User::ROLE_SUPER,
                'is_owner' => true,
            ])->save();
        });

        $this->discountEdits[$tenant->id] = (string) $tenant->agent_discount;
        $this->reset(['subdomain', 'adminName', 'adminEmail', 'adminPassword']);
        $this->agentDiscount = '20';
        $this->successMessage = '分站 '.SiteUrl::tenantHost($tenant->id)." 已创建，管理员 {$email} 已开通";
    }

    /**
     * 批发折扣 could only be set at creation before this — no way back in to
     * change an agent's margin afterward, which is the whole point of the
     * "调佣金" ask this exists for.
     */
    public function updateDiscount(string $id): void
    {
        $this->successMessage = '';
        $this->error = '';

        if ($this->denyIfNotSuper()) {
            return;
        }

        $tenant = Tenant::find($id);
        if (! $tenant) {
            $this->error = '分站不存在';

            return;
        }

        $value = $this->discountEdits[$id] ?? '';
        if ($value === '' || ! ctype_digit($value) || (int) $value < 0 || (int) $value > 90) {
            $this->error = '批发折扣需在 0-90 之间的整数';

            return;
        }

        $tenant->update(['agent_discount' => (int) $value]);
        $this->successMessage = "分站 {$id} 批发折扣已改为 {$value}%";
    }

    public function delete(string $id): void
    {
        $this->successMessage = '';
        $this->error = '';

        if ($this->denyIfNotSuper()) {
            return;
        }

        $tenant = Tenant::find($id);
        if ($tenant) {
            $tenant->delete(); // drops the tenant DB + its domains
            $this->purgeStaleDatabaseFile($id); // belt-and-suspenders, see purgeStaleDatabaseFile()
            unset($this->discountEdits[$id]);
            $this->successMessage = "分站 {$id} 已删除";
        }
    }

    /** 站长 tops up a 分站's 进货余额 (when the agent pays for stock). */
    public function creditWholesale(string $id): void
    {
        $this->successMessage = '';
        $this->error = '';

        if ($this->denyIfNotSuper()) {
            return;
        }

        $cents = (int) round(((float) $this->topupAmount) * 100);
        if ($cents <= 0) {
            $this->error = '充值金额需大于 0';

            return;
        }

        // The amount typed is FACE value — what the agent can go on to sell.
        // Spell out the cash owed, because that is the number 站长 has to have
        // actually collected, and quoting face value by mistake gives away the
        // whole margin.
        $tenant = \App\Models\Tenant::find($id);
        $wholesale = app(\App\Services\WholesaleService::class);
        $cash = $wholesale->cashPriceFor($tenant, $cents);

        $balance = $wholesale->credit($id, $cents, $cash);
        $this->topupAmount = '';
        $this->successMessage = "已给分站 {$id} 充值 ¥".number_format($cents / 100, 2)
            .' 进货余额（应收代理现金 ¥'.number_format($cash / 100, 2)
            .'），当前余额 ¥'.number_format($balance / 100, 2);
    }

    /**
     * "进入分站后台" — 站长 acts as that 分站's own admin (its is_owner
     * account) to manage its actual customers (adjust balance, ban, view
     * orders) using the exact same tooling a 分站 admin already has, instead
     * of duplicating that whole toolkit into this page. Hands off to
     * TenantImpersonationController via a short-lived signed cross-domain
     * token — see App\Support\TenantImpersonationToken for why that's a
     * custom token rather than Laravel's signed-route helper.
     */
    public function enterTenant(string $id): void
    {
        $this->successMessage = '';
        $this->error = '';

        if ($this->denyIfNotSuper()) {
            return;
        }

        $tenant = Tenant::find($id);
        if (! $tenant) {
            $this->error = '分站不存在';

            return;
        }

        $targetUserId = null;
        $tenant->run(function () use (&$targetUserId) {
            $owner = User::where('is_admin', true)->where('is_owner', true)->first()
                ?? User::where('is_admin', true)->first();
            $targetUserId = $owner?->id;
        });

        if (! $targetUserId) {
            $this->error = '该分站没有可用的管理员账号';

            return;
        }

        $host = $tenant->domains->first()?->domain ?? $tenant->id;
        $tenantHost = SiteUrl::tenantHost($host);
        if (! $tenantHost) {
            $this->error = '请先在 .env 配置 TENANCY_PARENT_DOMAIN';

            return;
        }

        $url = \App\Support\TenantImpersonationToken::url(
            $tenantHost, $tenant->id, $targetUserId, (int) auth()->id()
        );

        $this->redirect($url);
    }

    public function with(): array
    {
        $tenants = Tenant::with('domains')->latest()->get();

        // Deleting a 分站 drops its database outright — the only way back is
        // last night's backup. A generic "are you sure?" is easy to click
        // through, so the confirmation names what would actually be destroyed.
        $stats = [];
        foreach ($tenants as $tenant) {
            try {
                $stats[$tenant->getTenantKey()] = $tenant->run(fn () => [
                    'customers' => \App\Models\User::where('is_admin', false)->count(),
                    'active' => \App\Models\User::where('plan_expired_at', '>', now())->count()
                        + \App\Models\SubscriptionInstance::where('status', \App\Models\SubscriptionInstance::STATUS_ACTIVE)->count(),
                ]);
            } catch (\Throwable) {
                $stats[$tenant->getTenantKey()] = ['customers' => null, 'active' => null];
            }
        }

        return [
            'tenants' => $tenants,
            'stats' => $stats,
            'wholesale' => \App\Models\TenantWholesale::pluck('balance', 'tenant_id'),
            'tenantParentDomain' => SiteUrl::parentDomain(),
        ];
    }
};
?>

<div class="space-y-6">
    <div>
        <h1 class="text-xl font-semibold text-gray-900 dark:text-gray-100">分站管理</h1>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">为代理开通独立分站（子域 + 独立客户库 + 代理管理员）。共享节点/套餐/开通渠道仍归平台。</p>
    </div>

    @if ($successMessage)
        <div class="text-sm text-green-700 dark:text-green-400 bg-green-50 dark:bg-green-500/10 border border-green-100 dark:border-green-500/20 rounded-lg p-3">{{ $successMessage }}</div>
    @endif
    @if ($error)
        <div class="text-sm text-red-600 dark:text-red-400 bg-red-50 dark:bg-red-500/10 border border-red-100 dark:border-red-500/20 rounded-lg p-3">{{ $error }}</div>
    @endif

    {{-- 新建分站 --}}
    <div class="border border-gray-200 dark:border-gray-800 rounded-xl p-5 bg-white dark:bg-gray-900 max-w-2xl">
        <h2 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">新建分站</h2>
        <div class="grid sm:grid-cols-2 gap-3">
            <div>
                <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">子域</label>
                <div class="flex items-center gap-1">
                    <input type="text" wire:model="subdomain" placeholder="daili1" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
                    <span class="text-xs text-gray-400 whitespace-nowrap">{{ $tenantParentDomain ? '.'.$tenantParentDomain : '（未配置域名）' }}</span>
                </div>
                @error('subdomain') <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">批发折扣（% off）</label>
                <input type="number" min="0" max="90" wire:model="agentDiscount" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
                @error('agentDiscount') <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">代理管理员名称</label>
                <input type="text" wire:model="adminName" placeholder="代理名" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
                @error('adminName') <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">代理管理员邮箱</label>
                <input type="email" wire:model="adminEmail" placeholder="agent@example.com" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
                @error('adminEmail') <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">代理管理员密码</label>
                <input type="password" wire:model="adminPassword" autocomplete="new-password" placeholder="至少 8 位" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
                @error('adminPassword') <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p> @enderror
            </div>
        </div>
        <button wire:click="create" wire:loading.attr="disabled" class="mt-4 text-sm bg-gray-900 dark:bg-gray-100 text-white dark:text-gray-900 rounded-lg px-4 py-2">
            <span wire:loading.remove wire:target="create">创建分站</span>
            <span wire:loading wire:target="create">创建中（建库迁移）…</span>
        </button>
    </div>

    {{-- 分站列表 --}}
    <div>
        <h2 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">已有分站（{{ $tenants->count() }}）</h2>
        <table class="w-full text-sm bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-xl overflow-hidden">
            <thead class="bg-gray-50 dark:bg-gray-800/60 text-gray-500 dark:text-gray-400 text-xs">
                <tr>
                    <th class="text-left px-4 py-2">分站</th>
                    <th class="text-left px-4 py-2">批发折扣</th>
                    <th class="text-left px-4 py-2">客户</th>
                    <th class="text-left px-4 py-2">进货余额</th>
                    <th class="text-left px-4 py-2">充值进货余额</th>
                    <th class="text-right px-4 py-2">操作</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($tenants as $tenant)
                    <tr class="border-t border-gray-100 dark:border-gray-800">
                        <td class="px-4 py-2 font-mono text-gray-700 dark:text-gray-300">{{ $tenant->domains->first()?->domain ?? $tenant->id }}{{ $tenantParentDomain ? '.'.$tenantParentDomain : '' }}</td>
                        <td class="px-4 py-2 text-gray-500 dark:text-gray-400">
                            <div class="flex items-center gap-1">
                                <input type="number" min="0" max="90" wire:model="discountEdits.{{ $tenant->id }}" class="w-14 border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-2 py-1 text-xs">
                                <span class="text-xs">% off</span>
                                <button wire:click="updateDiscount('{{ $tenant->id }}')" class="text-xs text-teal-700 dark:text-teal-400 hover:underline">保存</button>
                            </div>
                        </td>
                        @php($st = $stats[$tenant->id] ?? ['customers' => null, 'active' => null])
                        <td class="px-4 py-2 text-gray-500 dark:text-gray-400">
                            {{ $st['customers'] ?? '—' }}
                            @if (($st['active'] ?? 0) > 0)
                                <span class="text-xs text-green-600 dark:text-green-400">（{{ $st['active'] }} 有效）</span>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-gray-900 dark:text-gray-100">¥{{ number_format(((int) ($wholesale[$tenant->id] ?? 0)) / 100, 2) }}</td>
                        <td class="px-4 py-2">
                            <div class="flex items-center gap-1">
                                <span class="text-xs text-gray-400">¥</span>
                                <input type="number" min="0" step="1" wire:model.live="topupAmount" placeholder="面额" class="w-20 border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-2 py-1 text-xs">
                                <button wire:click="creditWholesale('{{ $tenant->id }}')" class="text-xs border border-teal-300 dark:border-teal-800 text-teal-700 dark:text-teal-400 rounded-lg px-2 py-1">充值</button>
                            </div>
                            {{-- 折扣在这一步收掉，不在结算时。输的是面额（代理能卖多少），
                                 该收的现金是另一个数——不写出来迟早会按面额收错。 --}}
                            @if ((float) $topupAmount > 0)
                                @php($cash = (int) round(((float) $topupAmount) * 100 * (100 - (int) ($tenant->agent_discount ?? 0)) / 100))
                                <div class="text-xs text-teal-700 dark:text-teal-400 mt-1">
                                    应收现金 ¥{{ number_format($cash / 100, 2) }}
                                    <span class="text-gray-400 dark:text-gray-500">（{{ 100 - (int) ($tenant->agent_discount ?? 0) }}% 进货价）</span>
                                </div>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-right whitespace-nowrap">
                            @php($s = $stats[$tenant->id] ?? ['customers' => null, 'active' => null])
                            <button wire:click="enterTenant('{{ $tenant->id }}')" wire:loading.attr="disabled" class="text-xs text-teal-700 dark:text-teal-400 hover:underline mr-3">进入后台</button>
                            <button wire:click="delete('{{ $tenant->id }}')" wire:confirm="删除分站 {{ $tenant->id }}？&#10;&#10;该站现有 {{ $s['customers'] ?? '?' }} 个客户、{{ $s['active'] ?? '?' }} 个有效订阅，进货余额 ¥{{ number_format(($wholesale[$tenant->id] ?? 0) / 100, 2) }}。&#10;&#10;整个数据库会被删除，客户立即断服，只能从昨夜备份恢复。确定继续？" class="text-xs text-red-600 dark:text-red-400 hover:underline">删除</button>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-8 text-center text-gray-400 dark:text-gray-500">还没有分站</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
