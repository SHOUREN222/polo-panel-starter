<?php

use App\Models\TenantWholesale;
use App\Models\TenantWholesaleSettlement;
use App\Models\TenantWholesaleTopup;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * A 分站 admin's own view of their 进货余额 ledger — the credit side
 * (TenantWholesaleTopup, 站长 topping them up) merged with the debit side
 * (TenantWholesaleSettlement, their own orders consuming it). Both models are
 * CentralConnection, so these queries hit the central DB from tenant context;
 * scoped to this tenant's own id, same pattern as WholesaleService itself.
 */
new #[Layout('layouts.admin', ['title' => '进货流水'])] class extends Component
{
    /** Only a 分站 owes 站长 anything wholesale — meaningless on 主站. */
    public function mount(): void
    {
        abort_unless(tenant(), 404);
    }

    public function with(): array
    {
        // mount() already aborted if this isn't a 分站 request.
        $tenantId = tenant()->getTenantKey();

        $balance = (int) (TenantWholesale::where('tenant_id', $tenantId)->value('balance') ?? 0);

        // .toBase() before ->map(): Eloquent\Collection::merge() below expects
        // Model items (it dedupes by ->getKey()) — once mapped down to plain
        // arrays, only a plain Support\Collection's merge() is safe to use.
        $topups = TenantWholesaleTopup::where('tenant_id', $tenantId)->get()->toBase()->map(fn ($t) => [
            'type' => 'topup',
            'at' => $t->created_at,
            'amount' => $t->amount,
            'cash_collected' => $t->cash_collected,
            'trade_no' => null,
            'status' => null,
        ]);

        $settlements = TenantWholesaleSettlement::where('tenant_id', $tenantId)->get()->toBase()->map(fn ($s) => [
            'type' => 'settlement',
            'at' => $s->completed_at ?? $s->released_at ?? $s->reserved_at,
            'amount' => $s->amount,
            'cash_collected' => null,
            'trade_no' => $s->trade_no,
            'status' => $s->status,
        ]);

        $entries = $topups->merge($settlements)->sortByDesc('at')->values();

        return [
            'balance' => $balance,
            'entries' => $entries,
        ];
    }
};
?>

<div class="space-y-6">
    <div>
        <h1 class="text-xl font-semibold text-gray-900 dark:text-gray-100">进货流水</h1>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">站长给你充值的进货额度，和你自己订单消耗的明细。</p>
    </div>

    <div class="border border-gray-200 dark:border-gray-800 rounded-xl p-4 bg-white dark:bg-gray-900 max-w-xs">
        <div class="text-xs text-gray-500 dark:text-gray-400 mb-1">当前进货余额</div>
        <div class="text-xl font-semibold text-gray-900 dark:text-gray-100">¥{{ number_format($balance / 100, 2) }}</div>
    </div>

    <div class="border border-gray-200 dark:border-gray-800 rounded-xl bg-white dark:bg-gray-900 overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 dark:bg-gray-800/60 text-gray-500 dark:text-gray-400 text-xs">
                <tr>
                    <th class="text-left px-4 py-2">时间</th>
                    <th class="text-left px-4 py-2">类型</th>
                    <th class="text-left px-4 py-2">金额</th>
                    <th class="text-left px-4 py-2">备注</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($entries as $e)
                    <tr class="border-t border-gray-100 dark:border-gray-800">
                        <td class="px-4 py-3 text-gray-500 dark:text-gray-400">{{ $e['at']?->format('Y-m-d H:i') ?? '—' }}</td>
                        <td class="px-4 py-3">
                            @if ($e['type'] === 'topup')
                                <span class="text-xs bg-green-50 dark:bg-green-500/10 text-green-700 dark:text-green-400 rounded-full px-2 py-1">站长充值</span>
                            @else
                                @php
                                    $statusLabel = match ($e['status']) {
                                        'completed' => '订单消耗',
                                        'reserved' => '订单占用中',
                                        'released' => '已释放（订单取消）',
                                        default => $e['status'],
                                    };
                                    $statusStyles = $e['status'] === 'released'
                                        ? 'bg-gray-100 dark:bg-gray-800 text-gray-500'
                                        : 'bg-amber-50 dark:bg-amber-500/10 text-amber-700 dark:text-amber-400';
                                @endphp
                                <span class="text-xs {{ $statusStyles }} rounded-full px-2 py-1">{{ $statusLabel }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 {{ $e['type'] === 'topup' ? 'text-green-600 dark:text-green-400' : 'text-gray-900 dark:text-gray-100' }}">
                            {{ $e['type'] === 'topup' ? '+' : '−' }}¥{{ number_format($e['amount'] / 100, 2) }}
                        </td>
                        <td class="px-4 py-3 text-gray-500 dark:text-gray-400">
                            @if ($e['type'] === 'topup' && $e['cash_collected'] !== null)
                                实收现金 ¥{{ number_format($e['cash_collected'] / 100, 2) }}
                            @elseif ($e['trade_no'])
                                订单 {{ $e['trade_no'] }}
                            @else
                                —
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-4 py-8 text-center text-gray-400 dark:text-gray-500">还没有流水记录</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
