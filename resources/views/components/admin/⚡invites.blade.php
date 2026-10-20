<?php

use App\Models\Commission;
use App\Models\InviteCode;
use App\Models\Setting;
use App\Models\User;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts.admin', ['title' => '邀请管理'])] class extends Component
{
    use WithPagination;

    public string $tab = 'codes';
    public string $search = '';
    public string $statusFilter = ''; // '' | pending | confirmed | transferred | void
    public string $dateFrom = '';
    public string $dateTo = '';
    public string $successMessage = '';
    public string $error = '';

    public function updatingSearch(): void
    {
        $this->resetPage('codesPage');
        $this->resetPage('commissionsPage');
    }

    public function updatingStatusFilter(): void
    {
        $this->resetPage('commissionsPage');
    }

    public function updatingDateFrom(): void
    {
        $this->resetPage('commissionsPage');
    }

    public function updatingDateTo(): void
    {
        $this->resetPage('commissionsPage');
    }

    /**
     * Void a commission (fraud / correction). Only ones not yet paid out can be
     * voided — once transferred to balance it's real money and can't be clawed
     * back here. A voided commission never confirms and never transfers.
     */
    public function voidCommission(int $id): void
    {
        $this->successMessage = '';
        $this->error = '';

        $commission = Commission::find($id);
        if (! $commission) {
            return;
        }

        if ($commission->isTransferred()) {
            $this->error = '已划转到余额的佣金不能作废';

            return;
        }

        $commission->update(['status' => Commission::STATUS_VOID, 'confirmed_at' => null]);
        $this->successMessage = '已作废该笔佣金';
    }

    private function commissionQuery()
    {
        $q = Commission::with(['user', 'referredUser', 'order'])->latest();

        if ($this->search !== '') {
            $q->where(function ($sub) {
                $sub->whereHas('user', fn ($u) => $u->where('email', 'like', "%{$this->search}%"))
                    ->orWhereHas('referredUser', fn ($u) => $u->where('email', 'like', "%{$this->search}%"));
            });
        }
        if ($this->statusFilter !== '') {
            match ($this->statusFilter) {
                'pending' => $q->where('status', Commission::STATUS_PENDING),
                'confirmed' => $q->where('status', Commission::STATUS_CONFIRMED)->whereNull('transferred_at'),
                'transferred' => $q->whereNotNull('transferred_at'),
                'void' => $q->where('status', Commission::STATUS_VOID),
                default => null,
            };
        }
        if ($this->dateFrom !== '') {
            $q->whereDate('created_at', '>=', $this->dateFrom);
        }
        if ($this->dateTo !== '') {
            $q->whereDate('created_at', '<=', $this->dateTo);
        }

        return $q;
    }

    public function exportCsv()
    {
        $rows = $this->commissionQuery()->get();

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fprintf($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel reads Chinese
            fputcsv($out, ['状态', '邀请人', '被邀请人', '来源订单', '佣金(元)', '时间']);
            foreach ($rows as $c) {
                $status = $c->isVoid() ? '已作废' : ($c->isTransferred() ? '已划转' : ($c->isConfirmed() ? '已确认' : '确认中'));
                fputcsv($out, [
                    $status,
                    $c->user?->email ?? '已删除用户',
                    $c->referredUser?->email ?? '已删除用户',
                    $c->order?->trade_no ?? '—',
                    number_format($c->amount / 100, 2),
                    $c->created_at->format('Y-m-d H:i'),
                ]);
            }
            fclose($out);
        }, 'commissions-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function with(): array
    {
        $codesQuery = InviteCode::with('user')->withCount('registeredUsers')->latest();
        if ($this->tab === 'codes' && $this->search !== '') {
            $codesQuery->where(function ($q) {
                $q->where('code', 'like', "%{$this->search}%")
                    ->orWhereHas('user', fn ($u) => $u->where('email', 'like', "%{$this->search}%"));
            });
        }

        return [
            'codes' => $codesQuery->paginate(20, ['*'], 'codesPage'),
            'commissions' => $this->commissionQuery()->paginate(20, ['*'], 'commissionsPage'),
            'leaderboard' => User::withCount('referrals')
                ->withSum(['commissions as commission_total' => fn ($q) => $q->where('status', '!=', Commission::STATUS_VOID)], 'amount')
                ->whereHas('referrals')
                ->orderByDesc('referrals_count')
                ->limit(50)
                ->get(),
            'stats' => [
                'total' => (int) Commission::where('status', '!=', Commission::STATUS_VOID)->sum('amount'),
                'pending' => (int) Commission::where('status', Commission::STATUS_PENDING)->sum('amount'),
                'confirmed' => (int) Commission::where('status', Commission::STATUS_CONFIRMED)->whereNull('transferred_at')->sum('amount'),
                'transferred' => (int) Commission::whereNotNull('transferred_at')->sum('amount'),
            ],
            'settings' => [
                'enabled' => Setting::getBool('referral_enabled', true),
                'rate' => (int) Setting::get('commission_rate', 10),
                'hold_days' => (int) Setting::get('commission_hold_days', 0),
                'first_only' => Setting::getBool('commission_first_payment_only', false),
                'three_tier' => Setting::getBool('three_tier_commission_enabled', false),
                'tier1' => (int) Setting::get('commission_tier1_rate', 100),
                'tier2' => (int) Setting::get('commission_tier2_rate', 0),
                'tier3' => (int) Setting::get('commission_tier3_rate', 0),
            ],
        ];
    }
};
?>

<div class="space-y-6">
    <div>
        <h1 class="text-xl font-semibold text-gray-900 dark:text-gray-100">邀请管理</h1>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">查看邀请码、佣金记录和邀请排行。佣金比例和确认周期在<a href="{{ '/'.config('admin.path').'/system' }}" class="underline">系统配置</a>里调整。</p>
    </div>

    {{-- 当前佣金设置 --}}
    <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs bg-gray-50 dark:bg-gray-800/50 border border-gray-100 dark:border-gray-800 rounded-lg px-3 py-2">
        <span>分销 <span class="{{ $settings['enabled'] ? 'text-green-600 dark:text-green-400' : 'text-red-500' }} font-medium">{{ $settings['enabled'] ? '开启' : '关闭' }}</span></span>
        <span class="text-gray-500 dark:text-gray-400">佣金比例 <span class="text-gray-800 dark:text-gray-200 font-medium">{{ $settings['rate'] }}%</span></span>
        <span class="text-gray-500 dark:text-gray-400">确认周期 <span class="text-gray-800 dark:text-gray-200 font-medium">{{ $settings['hold_days'] }} 天</span></span>
        <span class="text-gray-500 dark:text-gray-400">仅首单 <span class="text-gray-800 dark:text-gray-200 font-medium">{{ $settings['first_only'] ? '是' : '否' }}</span></span>
        <span class="text-gray-500 dark:text-gray-400">三级分销 <span class="text-gray-800 dark:text-gray-200 font-medium">{{ $settings['three_tier'] ? "开（{$settings['tier1']}/{$settings['tier2']}/{$settings['tier3']}%）" : '关' }}</span></span>
    </div>

    @if ($successMessage)
        <div class="text-sm text-green-700 dark:text-green-400 bg-green-50 dark:bg-green-500/10 border border-green-100 dark:border-green-500/20 rounded-lg p-3">{{ $successMessage }}</div>
    @endif
    @if ($error)
        <div class="text-sm text-red-600 dark:text-red-400 bg-red-50 dark:bg-red-500/10 border border-red-100 dark:border-red-500/20 rounded-lg p-3">{{ $error }}</div>
    @endif

    {{-- 佣金汇总 --}}
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
        @foreach ([
            ['label' => '总佣金（不含作废）', 'value' => $stats['total'], 'cls' => 'text-gray-900 dark:text-gray-100'],
            ['label' => '确认中', 'value' => $stats['pending'], 'cls' => 'text-yellow-600 dark:text-yellow-400'],
            ['label' => '已确认待划转', 'value' => $stats['confirmed'], 'cls' => 'text-green-600 dark:text-green-400'],
            ['label' => '已划转', 'value' => $stats['transferred'], 'cls' => 'text-gray-500 dark:text-gray-400'],
        ] as $card)
            <div class="border border-gray-200 dark:border-gray-800 rounded-xl p-4 bg-white dark:bg-gray-900">
                <div class="text-xs text-gray-500 dark:text-gray-400">{{ $card['label'] }}</div>
                <div class="text-lg font-semibold {{ $card['cls'] }} mt-1">¥{{ number_format($card['value'] / 100, 2) }}</div>
            </div>
        @endforeach
    </div>

    <div class="flex gap-2 border-b border-gray-200 dark:border-gray-800">
        @foreach (['codes' => '邀请码', 'commissions' => '佣金记录', 'leaderboard' => '邀请排行'] as $key => $label)
            <button
                wire:click="$set('tab', '{{ $key }}')"
                class="text-sm px-3 py-2 border-b-2 {{ $tab === $key ? 'border-teal-600 text-gray-900 dark:text-gray-100' : 'border-transparent text-gray-500 dark:text-gray-400' }}"
            >{{ $label }}</button>
        @endforeach
    </div>

    @if ($tab !== 'leaderboard')
        <div class="flex flex-wrap items-center gap-2">
            <input
                type="text"
                wire:model.live.debounce.400ms="search"
                placeholder="{{ $tab === 'codes' ? '搜索邀请码或所属用户邮箱...' : '搜索邀请人或被邀请人邮箱...' }}"
                class="flex-1 min-w-48 max-w-xs border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm"
            >
            @if ($tab === 'commissions')
                <select wire:model.live="statusFilter" class="border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
                    <option value="">全部状态</option>
                    <option value="pending">确认中</option>
                    <option value="confirmed">已确认待划转</option>
                    <option value="transferred">已划转</option>
                    <option value="void">已作废</option>
                </select>
                <input type="date" wire:model.live="dateFrom" class="border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-2 py-2 text-sm">
                <span class="text-xs text-gray-400">至</span>
                <input type="date" wire:model.live="dateTo" class="border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-2 py-2 text-sm">
                <button wire:click="exportCsv" class="text-xs border border-gray-300 dark:border-gray-700 text-gray-700 dark:text-gray-300 rounded-lg px-3 py-2 hover:border-gray-900 dark:hover:border-gray-400">导出 CSV</button>
            @endif
        </div>
    @endif

    @if ($tab === 'codes')
        <table class="w-full text-sm bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-xl overflow-hidden">
            <thead class="bg-gray-50 dark:bg-gray-800/60 text-gray-500 dark:text-gray-400 text-xs">
                <tr>
                    <th class="text-left px-4 py-2">邀请码</th>
                    <th class="text-left px-4 py-2">所属用户</th>
                    <th class="text-left px-4 py-2">注册人数</th>
                    <th class="text-left px-4 py-2">创建时间</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($codes as $code)
                    <tr class="border-t border-gray-100 dark:border-gray-800">
                        <td class="px-4 py-2 font-mono text-gray-700 dark:text-gray-300">{{ $code->code }}</td>
                        <td class="px-4 py-2 text-gray-500 dark:text-gray-400">{{ $code->user?->email ?? '已删除用户' }}</td>
                        <td class="px-4 py-2 text-gray-500 dark:text-gray-400">{{ $code->registered_users_count }} 人</td>
                        <td class="px-4 py-2 text-gray-500 dark:text-gray-400">{{ $code->created_at->format('Y-m-d H:i') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-4 py-8 text-center text-gray-400 dark:text-gray-500">还没有任何邀请码</td></tr>
                @endforelse
            </tbody>
        </table>
        {{ $codes->links() }}
    @elseif ($tab === 'commissions')
        <table class="w-full text-sm bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-xl overflow-hidden">
            <thead class="bg-gray-50 dark:bg-gray-800/60 text-gray-500 dark:text-gray-400 text-xs">
                <tr>
                    <th class="text-left px-4 py-2">状态</th>
                    <th class="text-left px-4 py-2">邀请人</th>
                    <th class="text-left px-4 py-2">被邀请人</th>
                    <th class="text-left px-4 py-2">来源订单</th>
                    <th class="text-left px-4 py-2">佣金</th>
                    <th class="text-left px-4 py-2">时间</th>
                    <th class="text-left px-4 py-2"></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($commissions as $commission)
                    <tr class="border-t border-gray-100 dark:border-gray-800">
                        <td class="px-4 py-2">
                            @if ($commission->isVoid())
                                <span class="text-xs bg-red-50 dark:bg-red-500/10 text-red-600 dark:text-red-400 rounded-full px-2 py-1">已作废</span>
                            @elseif ($commission->isTransferred())
                                <span class="text-xs bg-gray-100 dark:bg-gray-800 text-gray-500 rounded-full px-2 py-1">已划转</span>
                            @elseif ($commission->isConfirmed())
                                <span class="text-xs bg-green-50 dark:bg-green-500/10 text-green-700 dark:text-green-400 rounded-full px-2 py-1">已确认</span>
                            @else
                                <span class="text-xs bg-yellow-50 dark:bg-yellow-500/10 text-yellow-700 dark:text-yellow-400 rounded-full px-2 py-1">确认中</span>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-gray-700 dark:text-gray-300">{{ $commission->user?->email ?? '已删除用户' }}</td>
                        <td class="px-4 py-2 text-gray-500 dark:text-gray-400">{{ $commission->referredUser?->email ?? '已删除用户' }}</td>
                        <td class="px-4 py-2 text-gray-500 dark:text-gray-400 font-mono text-xs">{{ $commission->order?->trade_no ?? '—' }}</td>
                        <td class="px-4 py-2 text-gray-900 dark:text-gray-100">¥{{ number_format($commission->amount / 100, 2) }}</td>
                        <td class="px-4 py-2 text-gray-500 dark:text-gray-400">{{ $commission->created_at->format('Y-m-d H:i') }}</td>
                        <td class="px-4 py-2 text-right">
                            @if (! $commission->isTransferred() && ! $commission->isVoid())
                                <button wire:click="voidCommission({{ $commission->id }})" wire:confirm="确定作废这笔佣金？作废后邀请人无法划转它。" class="text-xs text-red-600 dark:text-red-400 hover:underline">作废</button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-8 text-center text-gray-400 dark:text-gray-500">没有符合条件的佣金记录</td></tr>
                @endforelse
            </tbody>
        </table>
        {{ $commissions->links() }}
    @elseif ($tab === 'leaderboard')
        <table class="w-full text-sm bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-xl overflow-hidden">
            <thead class="bg-gray-50 dark:bg-gray-800/60 text-gray-500 dark:text-gray-400 text-xs">
                <tr>
                    <th class="text-left px-4 py-2">#</th>
                    <th class="text-left px-4 py-2">用户</th>
                    <th class="text-left px-4 py-2">拉来人数</th>
                    <th class="text-left px-4 py-2">累计佣金</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($leaderboard as $i => $u)
                    <tr class="border-t border-gray-100 dark:border-gray-800">
                        <td class="px-4 py-2 text-gray-400 dark:text-gray-500">{{ $i + 1 }}</td>
                        <td class="px-4 py-2 text-gray-700 dark:text-gray-300">{{ $u->email }}</td>
                        <td class="px-4 py-2 text-gray-900 dark:text-gray-100 font-medium">{{ $u->referrals_count }} 人</td>
                        <td class="px-4 py-2 text-gray-500 dark:text-gray-400">¥{{ number_format(($u->commission_total ?? 0) / 100, 2) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-4 py-8 text-center text-gray-400 dark:text-gray-500">还没有人成功邀请</td></tr>
                @endforelse
            </tbody>
        </table>
    @endif
</div>
