<?php

use App\Models\Server;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts.admin', ['title' => '节点监控'])] class extends Component
{
    use WithPagination;

    public string $successMessage = '';

    // 静态IP中转两台（8446 独立 xray 服务，SSH 探测而非心跳上报）的实时状态
    public array $relayStatuses = [];

    public function refreshRelayStatus(): void
    {
        try {
            $this->relayStatuses = app(\App\Services\NodeRelayService::class)->relayStatuses();
        } catch (\Throwable $e) {
            $this->relayStatuses = [];
        }
    }

    public function with(): array
    {
        return [
            'servers' => Server::orderBy('id')->paginate(20),
            'heartbeatUrl' => url('/api/servers/heartbeat'),
        ];
    }
};
?>

<div class="space-y-6" wire:init="refreshRelayStatus">
    <div>
        <h1 class="text-xl font-semibold text-gray-900 dark:text-gray-100">节点监控</h1>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
            节点在线状态基于心跳上报判断，5 分钟内有上报视为在线。真实节点上用 cron 定时请求
            <code class="text-xs bg-gray-100 dark:bg-gray-800 rounded px-1.5 py-0.5">{{ $heartbeatUrl }}</code>
            即可接入。
        </p>
    </div>

    @if ($successMessage)
        <div class="text-sm text-green-700 dark:text-green-400 bg-green-50 dark:bg-green-500/10 border border-green-100 dark:border-green-500/20 rounded-lg p-3">{{ $successMessage }}</div>
    @endif

    <table class="w-full text-sm bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-xl overflow-hidden">
        <thead class="bg-gray-50 dark:bg-gray-800/60 text-gray-500 dark:text-gray-400 text-xs">
            <tr>
                <th class="text-left px-4 py-2">状态</th>
                <th class="text-left px-4 py-2">名称</th>
                <th class="text-left px-4 py-2">地址</th>
                <th class="text-left px-4 py-2">最后上报</th>
                <th class="text-left px-4 py-2">上报命令</th>
                <th class="text-left px-4 py-2"></th>
            </tr>
        </thead>
        <tbody>
            @forelse ($servers as $server)
                <tr class="border-t border-gray-100 dark:border-gray-800">
                    <td class="px-4 py-2">
                        @if ($server->isOnline())
                            <span class="text-xs bg-green-50 dark:bg-green-500/10 text-green-700 dark:text-green-400 rounded-full px-2 py-1">在线</span>
                        @else
                            <span class="text-xs bg-gray-100 dark:bg-gray-800 text-gray-500 rounded-full px-2 py-1">离线</span>
                        @endif
                    </td>
                    <td class="px-4 py-2 text-gray-700 dark:text-gray-300">{{ $server->name }}</td>
                    <td class="px-4 py-2 text-gray-500 dark:text-gray-400 font-mono text-xs">{{ $server->host }}:{{ $server->port }}</td>
                    <td class="px-4 py-2 text-gray-500 dark:text-gray-400">{{ $server->last_seen_at?->diffForHumans() ?? '从未上报' }}</td>
                    <td class="px-4 py-2 text-gray-400 dark:text-gray-500 font-mono text-xs">curl -X POST {{ $heartbeatUrl }} -d secret={{ $server->heartbeat_secret }}</td>
                    <td class="px-4 py-2"></td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="px-4 py-8 text-center text-gray-400 dark:text-gray-500">还没有配置任何服务器</td>
                </tr>
            @endforelse

            {{-- 静态IP中转两台：SSH 实时探测（非心跳上报），也在这里统一监控 --}}
            @if (! empty($relayStatuses))
                <tr class="border-t-2 border-gray-200 dark:border-gray-700 bg-gray-50/60 dark:bg-gray-800/30">
                    <td colspan="6" class="px-4 py-1.5 text-xs text-gray-400 dark:text-gray-500">静态IP 中转节点（8446，SSH 探测）</td>
                </tr>
                @foreach ($relayStatuses as $n)
                    <tr class="border-t border-gray-100 dark:border-gray-800">
                        <td class="px-4 py-2">
                            @if ($n['reachable'] && $n['active'] && $n['listening'])
                                <span class="text-xs bg-green-50 dark:bg-green-500/10 text-green-700 dark:text-green-400 rounded-full px-2 py-1">在线</span>
                            @elseif (! $n['reachable'])
                                <span class="text-xs bg-red-50 dark:bg-red-500/10 text-red-700 dark:text-red-400 rounded-full px-2 py-1">离线</span>
                            @else
                                <span class="text-xs bg-amber-50 dark:bg-amber-500/10 text-amber-700 dark:text-amber-400 rounded-full px-2 py-1">{{ ! $n['active'] ? '服务未运行' : '未监听' }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-gray-700 dark:text-gray-300">{{ $n['label'] }}中转</td>
                        <td class="px-4 py-2 text-gray-500 dark:text-gray-400 font-mono text-xs">{{ $n['host'] }}:{{ $n['port'] }}</td>
                        <td class="px-4 py-2 text-gray-500 dark:text-gray-400">{{ $n['last_sync'] ? \Illuminate\Support\Carbon::parse($n['last_sync'])->diffForHumans().'同步' : '实时探测' }}</td>
                        <td class="px-4 py-2 text-gray-400 dark:text-gray-500 text-xs">承载 {{ $n['lines'] }} 条线路 · SSH 探测（非心跳）</td>
                        <td class="px-4 py-2 text-right text-xs text-gray-400 dark:text-gray-500">在「服务器管理」运维</td>
                    </tr>
                @endforeach
            @else
                <tr wire:loading wire:target="refreshRelayStatus">
                    <td colspan="6" class="px-4 py-2 text-xs text-gray-400 dark:text-gray-500">正在探测静态IP中转节点…</td>
                </tr>
            @endif
        </tbody>
    </table>

    {{ $servers->links() }}
</div>
