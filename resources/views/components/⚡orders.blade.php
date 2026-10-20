<?php

use App\Models\Order;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.app', ['title' => '我的订单'])] class extends Component
{
    public function with(): array
    {
        $user = auth()->user()->fresh();

        return [
            'balance' => $user->balance,
            'orders' => $user->orders()->latest()->get(),
        ];
    }
};
?>

<div class="space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-xl font-semibold text-gray-900 dark:text-gray-100">我的订单</h1>
        <div class="text-sm text-gray-500 dark:text-gray-400">账户余额：<span class="font-medium text-gray-900 dark:text-gray-100">¥{{ number_format($balance / 100, 2) }}</span></div>
    </div>

    <p class="text-sm text-gray-500 dark:text-gray-400">待支付订单请到<a href="/plans" class="underline">购买订阅</a>页完成付款。</p>

    @if ($orders->count())
        <table class="w-full text-sm bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-xl overflow-hidden">
            <thead class="bg-gray-50 dark:bg-gray-800/60 text-gray-500 dark:text-gray-400 text-xs">
                <tr>
                    <th class="text-left px-4 py-2">订单号</th>
                    <th class="text-left px-4 py-2">类型</th>
                    <th class="text-left px-4 py-2">金额</th>
                    <th class="text-left px-4 py-2">状态</th>
                    <th class="text-left px-4 py-2">时间</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($orders as $order)
                    <tr class="border-t border-gray-100 dark:border-gray-800 text-gray-700 dark:text-gray-300">
                        <td class="px-4 py-2 font-mono text-xs">{{ $order->trade_no }}</td>
                        <td class="px-4 py-2 text-xs {{ $order->isRenewal() || $order->isUpgrade() ? 'text-blue-600 dark:text-blue-400' : 'text-gray-500 dark:text-gray-400' }}">{{ $order->typeLabel() }}</td>
                        <td class="px-4 py-2">¥{{ number_format($order->total_amount / 100, 2) }}</td>
                        <td class="px-4 py-2">
                            @if ($order->status === 'paid')
                                <span class="text-xs bg-green-50 dark:bg-green-500/10 text-green-700 dark:text-green-400 rounded-full px-2 py-0.5">已支付</span>
                            @elseif ($order->status === 'pending')
                                <span class="text-xs bg-yellow-50 dark:bg-yellow-500/10 text-yellow-700 dark:text-yellow-400 rounded-full px-2 py-0.5">待支付</span>
                            @else
                                <span class="text-xs bg-gray-100 dark:bg-gray-800 text-gray-500 rounded-full px-2 py-0.5">已取消</span>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-gray-500 dark:text-gray-400">{{ ($order->paid_at ?? $order->created_at)->format('Y-m-d H:i') }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @else
        <p class="text-sm text-gray-500 dark:text-gray-400">还没有订单。</p>
    @endif
</div>
