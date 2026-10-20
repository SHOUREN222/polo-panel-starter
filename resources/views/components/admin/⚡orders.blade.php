<?php

use App\Models\Order;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts.admin', ['title' => '订单管理'])] class extends Component
{
    use WithPagination;

    public string $statusFilter = '';
    public string $search = '';
    public string $successMessage = '';
    public string $error = '';

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingStatusFilter(): void
    {
        $this->resetPage();
    }

    /**
     * Delete a single order. Only PENDING (never-paid) orders can be removed —
     * paid orders are permanent revenue records (they drive stats/commission),
     * so deleting them is blocked.
     */
    public function deleteOrder(int $id): void
    {
        $this->successMessage = '';
        $this->error = '';
        $order = Order::findOrFail($id);

        if ($order->status !== Order::STATUS_PENDING) {
            $this->error = '已支付订单是收入记录，不可删除';

            return;
        }

        $order->delete();
        $this->successMessage = '订单已删除';
    }

    /** Bulk-remove all pending (never-paid) orders — for clearing abandoned/test orders. */
    public function clearPending(): void
    {
        $count = Order::where('status', Order::STATUS_PENDING)->delete();
        $this->successMessage = "已清理 {$count} 个待支付订单";
        $this->resetPage();
    }

    public function with(): array
    {
        $query = Order::with(['user:id,email', 'plan:id,name'])->latest();

        if ($this->statusFilter !== '') {
            $query->where('status', $this->statusFilter);
        }

        if ($this->search !== '') {
            $query->where(function ($q) {
                $q->where('trade_no', 'like', "%{$this->search}%")
                    ->orWhereHas('user', fn ($u) => $u->where('email', 'like', "%{$this->search}%"));
            });
        }

        return [
            'orders' => $query->paginate(20),
        ];
    }
};
?>

<div class="space-y-6">
    <div class="flex items-center justify-between gap-3">
        <h1 class="text-xl font-semibold text-gray-900 dark:text-gray-100">订单管理</h1>
        <div class="flex items-center gap-2">
            <input
                type="text"
                wire:model.live.debounce.400ms="search"
                placeholder="搜索订单号或用户邮箱..."
                class="text-sm border border-gray-300 dark:border-gray-700 dark:bg-gray-800 text-gray-700 dark:text-gray-300 rounded-lg px-3 py-1.5 w-64"
            >
            <select wire:model.live="statusFilter" class="text-sm border border-gray-300 dark:border-gray-700 text-gray-700 dark:text-gray-300 rounded-lg px-3 py-1.5">
                <option value="">全部状态</option>
                <option value="pending">待支付</option>
                <option value="paid">已支付</option>
                <option value="cancelled">已取消</option>
            </select>
            <button wire:click="clearPending" wire:confirm="确定删除所有「待支付」订单？（已支付的不受影响）" class="text-sm border border-red-300 dark:border-red-800 text-red-600 dark:text-red-400 rounded-lg px-3 py-1.5 shrink-0">清理待支付</button>
        </div>
    </div>

    @if ($successMessage)
        <div class="text-sm text-green-700 dark:text-green-400 bg-green-50 dark:bg-green-500/10 border border-green-100 dark:border-green-500/20 rounded-lg p-3">{{ $successMessage }}</div>
    @endif
    @if ($error)
        <div class="text-sm text-red-600 dark:text-red-400 bg-red-50 dark:bg-red-500/10 border border-red-100 dark:border-red-500/20 rounded-lg p-3">{{ $error }}</div>
    @endif

    <table class="w-full text-sm bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-xl overflow-hidden">
        <thead class="bg-gray-50 dark:bg-gray-800/60 text-gray-500 dark:text-gray-400 text-xs">
            <tr>
                <th class="text-left px-4 py-2">订单号</th>
                <th class="text-left px-4 py-2">用户</th>
                <th class="text-left px-4 py-2">套餐</th>
                <th class="text-left px-4 py-2">类型</th>
                <th class="text-left px-4 py-2">金额</th>
                <th class="text-left px-4 py-2">余额抵扣</th>
                <th class="text-left px-4 py-2">状态</th>
                <th class="text-left px-4 py-2">支付时间</th>
                <th class="text-left px-4 py-2"></th>
            </tr>
        </thead>
        <tbody>
            @forelse ($orders as $order)
                <tr class="border-t border-gray-100 dark:border-gray-800">
                    <td class="px-4 py-2 font-mono text-xs text-gray-900 dark:text-gray-100">{{ $order->trade_no }}</td>
                    <td class="px-4 py-2 text-gray-500 dark:text-gray-400">{{ $order->user->email ?? '-' }}</td>
                    <td class="px-4 py-2 text-gray-500 dark:text-gray-400">{{ $order->plan->name ?? '-' }}</td>
                    <td class="px-4 py-2 text-xs {{ $order->isRenewal() || $order->isUpgrade() ? 'text-blue-600 dark:text-blue-400' : 'text-gray-500 dark:text-gray-400' }}">{{ $order->typeLabel() }}</td>
                    <td class="px-4 py-2 text-gray-700 dark:text-gray-300">¥{{ number_format($order->total_amount / 100, 2) }}</td>
                    <td class="px-4 py-2 text-gray-500 dark:text-gray-400">
                        @if ($order->balance_amount > 0)
                            <span class="text-teal-600 dark:text-teal-400">¥{{ number_format($order->balance_amount / 100, 2) }}</span>
                        @else
                            -
                        @endif
                    </td>
                    <td class="px-4 py-2 text-gray-700 dark:text-gray-300">
                        @if ($order->status === 'paid')
                            <span class="text-xs bg-green-50 dark:bg-green-500/10 text-green-700 dark:text-green-400 rounded-full px-2 py-1">已支付</span>
                        @elseif ($order->status === 'pending')
                            <span class="text-xs bg-yellow-50 dark:bg-yellow-500/10 text-yellow-700 dark:text-yellow-400 rounded-full px-2 py-1">待支付</span>
                        @else
                            <span class="text-xs bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-400 rounded-full px-2 py-1">已取消</span>
                        @endif
                    </td>
                    <td class="px-4 py-2 text-gray-500 dark:text-gray-400">{{ $order->paid_at?->format('Y-m-d H:i') ?? '-' }}</td>
                    <td class="px-4 py-2 text-right">
                        @if ($order->status === 'pending')
                            <button wire:click="deleteOrder({{ $order->id }})" wire:confirm="确定删除这个待支付订单？" class="text-xs text-red-600 dark:text-red-400 hover:text-red-800 dark:hover:text-red-300">删除</button>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="9" class="px-4 py-6 text-center text-gray-400 dark:text-gray-500">还没有订单</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    {{ $orders->links() }}
</div>
