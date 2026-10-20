<?php

use App\Models\Commission;
use App\Models\InviteCode;
use App\Models\Setting;
use App\Services\CommissionService;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app', ['title' => '我的邀请'])] class extends Component
{
    use WithPagination;

    public string $successMessage = '';
    public string $error = '';

    public function mount(): void
    {
        abort_unless(Setting::getBool('referral_enabled', true), 404);

        app(CommissionService::class)->confirmDueCommissions(auth()->user());
    }

    public function generateCode(): void
    {
        $this->successMessage = '';
        $this->error = '';

        $max = (int) Setting::get('max_invite_codes', 5);
        $current = auth()->user()->inviteCodes()->count();

        if ($current >= $max) {
            $this->error = "最多只能生成 {$max} 个邀请码";

            return;
        }

        InviteCode::create(['user_id' => auth()->id()]);
        $this->successMessage = '邀请码已生成';
    }

    public function transfer(): void
    {
        $this->successMessage = '';
        $this->error = '';

        try {
            $amount = app(CommissionService::class)->transfer(auth()->user());
            $this->successMessage = '已划转 ¥' . number_format($amount / 100, 2) . ' 到账户余额';
        } catch (ValidationException $e) {
            $this->error = collect($e->errors())->flatten()->first();
        }
    }

    public function with(): array
    {
        $user = auth()->user()->fresh();
        $commissionService = app(CommissionService::class);

        $available = $commissionService->untransferredCommission($user);

        $pending = Commission::where('user_id', $user->id)
            ->where('status', Commission::STATUS_PENDING)
            ->sum('amount');

        $totalEarned = Commission::where('user_id', $user->id)
            ->where('status', Commission::STATUS_CONFIRMED)
            ->sum('amount');

        $inviteCodes = $user->inviteCodes()->withCount('registeredUsers')->latest()->get();
        $maxInviteCodes = (int) Setting::get('max_invite_codes', 5);

        return [
            'availableCommission' => $available,
            'pendingCommission' => $pending,
            'totalEarned' => $totalEarned,
            'registeredCount' => $user->referrals()->count(),
            'commissionRate' => (int) Setting::get('commission_rate', 10),
            'inviteCodes' => $inviteCodes,
            'maxInviteCodes' => $maxInviteCodes,
            'canGenerateMore' => $inviteCodes->count() < $maxInviteCodes,
            'ledger' => Commission::where('user_id', $user->id)
                ->whereNotNull('confirmed_at')
                ->orderByDesc('confirmed_at')
                ->paginate(10),
            'siteUrl' => Setting::get('site_url') ?: url('/'),
        ];
    }
};
?>

<div class="space-y-6" x-data="{ copied: null }">
    <h1 class="text-xl font-semibold text-gray-900 dark:text-gray-100">我的邀请</h1>

    @if ($successMessage)
        <div class="text-sm text-green-700 dark:text-green-400 bg-green-50 dark:bg-green-500/10 border border-green-100 dark:border-green-500/20 rounded-lg p-3">{{ $successMessage }}</div>
    @endif
    @if ($error)
        <div class="text-sm text-red-600 dark:text-red-400 bg-red-50 dark:bg-red-500/10 border border-red-100 dark:border-red-500/20 rounded-lg p-3">{{ $error }}</div>
    @endif

    {{-- 佣金余额 --}}
    <div class="border border-gray-200 dark:border-gray-800 rounded-xl p-5 bg-white dark:bg-gray-900">
        <div class="text-sm text-gray-500 dark:text-gray-400 mb-1">当前剩余佣金</div>
        <div class="flex items-baseline gap-2 mb-4">
            <span class="text-3xl font-semibold text-gray-900 dark:text-gray-100">{{ number_format($availableCommission / 100, 2) }}</span>
            <span class="text-sm text-gray-400 dark:text-gray-500">CNY</span>
        </div>

        <button wire:click="transfer" wire:loading.attr="disabled" class="text-sm bg-gray-900 dark:bg-gray-100 text-white dark:text-gray-900 rounded-lg px-4 py-2">划转到余额</button>
        <p class="text-xs text-gray-400 dark:text-gray-500 mt-2">佣金可划转到账户余额，用于购买或续费套餐。</p>
    </div>

    {{-- 统计 --}}
    <div class="border border-gray-200 dark:border-gray-800 rounded-xl bg-white dark:bg-gray-900 divide-y divide-gray-100 dark:divide-gray-800">
        <div class="flex items-center justify-between px-5 py-3 text-sm">
            <span class="text-gray-500 dark:text-gray-400">已注册用户数</span>
            <span class="text-gray-900 dark:text-gray-100">{{ $registeredCount }} 人</span>
        </div>
        <div class="flex items-center justify-between px-5 py-3 text-sm">
            <span class="text-gray-500 dark:text-gray-400">佣金比例</span>
            <span class="text-gray-900 dark:text-gray-100">{{ $commissionRate }}%</span>
        </div>
        <div class="flex items-center justify-between px-5 py-3 text-sm">
            <span class="text-gray-500 dark:text-gray-400">确认中的佣金</span>
            <span class="text-gray-900 dark:text-gray-100">¥{{ number_format($pendingCommission / 100, 2) }}</span>
        </div>
        <div class="flex items-center justify-between px-5 py-3 text-sm">
            <span class="text-gray-500 dark:text-gray-400">累计获得佣金</span>
            <span class="text-gray-900 dark:text-gray-100">¥{{ number_format($totalEarned / 100, 2) }}</span>
        </div>
    </div>

    {{-- 邀请码管理 --}}
    <div class="border border-gray-200 dark:border-gray-800 rounded-xl bg-white dark:bg-gray-900">
        <div class="flex items-center justify-between px-5 py-3 border-b border-gray-100 dark:border-gray-800">
            <div>
                <h2 class="text-sm font-medium text-gray-700 dark:text-gray-300">邀请码管理</h2>
                <p class="text-xs text-gray-400 dark:text-gray-500 mt-0.5">已生成 {{ $inviteCodes->count() }} / {{ $maxInviteCodes }} 个</p>
            </div>
            <button
                wire:click="generateCode"
                @disabled(! $canGenerateMore)
                class="text-xs rounded-lg px-3 py-1.5 {{ $canGenerateMore ? 'bg-gray-900 dark:bg-gray-100 text-white dark:text-gray-900' : 'bg-gray-100 dark:bg-gray-800 text-gray-400 dark:text-gray-600 cursor-not-allowed' }}"
            >生成邀请码</button>
        </div>

        @if ($inviteCodes->count())
            <table class="w-full text-sm">
                <thead class="text-gray-500 dark:text-gray-400 text-xs">
                    <tr>
                        <th class="text-left px-5 py-2">邀请码</th>
                        <th class="text-left px-5 py-2">邀请链接</th>
                        <th class="text-left px-5 py-2">注册人数</th>
                        <th class="text-left px-5 py-2">创建时间</th>
                        <th class="text-left px-5 py-2"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($inviteCodes as $code)
                        @php $link = $siteUrl . '/register?ref=' . $code->code; @endphp
                        <tr class="border-t border-gray-100 dark:border-gray-800">
                            <td class="px-5 py-2 font-mono text-gray-700 dark:text-gray-300">{{ $code->code }}</td>
                            <td class="px-5 py-2 text-gray-500 dark:text-gray-400 font-mono text-xs">{{ $link }}</td>
                            <td class="px-5 py-2 text-gray-500 dark:text-gray-400">{{ $code->registered_users_count }} 人</td>
                            <td class="px-5 py-2 text-gray-500 dark:text-gray-400">{{ $code->created_at->format('Y-m-d H:i') }}</td>
                            <td class="px-5 py-2 text-right">
                                <button
                                    type="button"
                                    x-ref="btn{{ $code->id }}"
                                    data-value="{{ $link }}"
                                    @click="copyToClipboard($refs['btn{{ $code->id }}'].dataset.value); copied = {{ $code->id }}; setTimeout(() => copied = null, 1500)"
                                    class="text-xs text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-100"
                                >
                                    <span x-show="copied !== {{ $code->id }}">复制链接</span>
                                    <span x-show="copied === {{ $code->id }}" x-cloak>已复制</span>
                                </button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @else
            <p class="text-sm text-gray-500 dark:text-gray-400 px-5 py-8 text-center">还没有邀请码，点右上角生成一个。</p>
        @endif
    </div>

    {{-- 佣金发放记录 --}}
    <div class="border border-gray-200 dark:border-gray-800 rounded-xl bg-white dark:bg-gray-900">
        <h2 class="text-sm font-medium text-gray-700 dark:text-gray-300 px-5 py-3 border-b border-gray-100 dark:border-gray-800">佣金发放记录</h2>

        @if ($ledger->count())
            <table class="w-full text-sm">
                <thead class="text-gray-500 dark:text-gray-400 text-xs">
                    <tr>
                        <th class="text-left px-5 py-2">发放时间</th>
                        <th class="text-left px-5 py-2">佣金</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($ledger as $commission)
                        <tr class="border-t border-gray-100 dark:border-gray-800">
                            <td class="px-5 py-2 text-gray-500 dark:text-gray-400">{{ $commission->confirmed_at->format('Y-m-d H:i') }}</td>
                            <td class="px-5 py-2 text-gray-900 dark:text-gray-100">¥{{ number_format($commission->amount / 100, 2) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <div class="px-5 py-3">
                {{ $ledger->links() }}
            </div>
        @else
            <p class="text-sm text-gray-500 dark:text-gray-400 px-5 py-8 text-center">还没有佣金记录。</p>
        @endif
    </div>
</div>
