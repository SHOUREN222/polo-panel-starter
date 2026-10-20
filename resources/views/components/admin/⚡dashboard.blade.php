<?php

use App\Models\Commission;
use App\Models\Order;
use App\Models\Server;
use App\Models\Setting;
use App\Models\SubscriptionInstance;
use App\Models\Ticket;
use App\Models\TrafficLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.admin', ['title' => '仪表盘'])] class extends Component
{
    private function formatBytesShort(int $bytes): string
    {
        if ($bytes <= 0) {
            return '0 MB';
        }
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = min((int) floor(log($bytes, 1024)), count($units) - 1);

        return round($bytes / (1024 ** $i), 2).' '.$units[$i];
    }

    private function formatDurationShort(int $seconds): string
    {
        if ($seconds < 60) {
            return "{$seconds} 秒";
        }
        if ($seconds < 3600) {
            return round($seconds / 60).' 分钟';
        }

        return round($seconds / 3600, 1).' 小时';
    }

    /** Signed percent change vs a baseline, or null when there's no baseline. */
    private function pctChange(int $current, int $previous): ?float
    {
        if ($previous <= 0) {
            return null;
        }

        return round(($current - $previous) / $previous * 100, 1);
    }

    public function with(): array
    {
        $today = now()->startOfDay();
        $monthStart = now()->startOfMonth();
        $chartStart = now()->subDays(29)->startOfDay();

        $todayRevenue = (int) Order::where('status', Order::STATUS_PAID)->where('paid_at', '>=', $today)->sum('total_amount');
        $monthRevenue = (int) Order::where('status', Order::STATUS_PAID)->where('paid_at', '>=', $monthStart)->sum('total_amount');
        $pendingTickets = Ticket::where('status', Ticket::STATUS_OPEN)->count();
        $pendingCommission = (int) Commission::where('status', Commission::STATUS_PENDING)->sum('amount');
        $monthNewUsers = User::where('created_at', '>=', $monthStart)->count();
        $totalUsers = User::count();
        $monthUpload = (int) TrafficLog::where('date', '>=', $monthStart->toDateString())->sum('upload_bytes');
        $monthDownload = (int) TrafficLog::where('date', '>=', $monthStart->toDateString())->sum('download_bytes');

        // 活跃订阅（共享 + ISP）+ 未来 7 天到期
        $soon = now()->addDays(7);
        $activeShared = User::where('plan_expired_at', '>', now())->count();
        $activeIsp = SubscriptionInstance::where('status', SubscriptionInstance::STATUS_ACTIVE)->where('expired_at', '>', now())->count();
        $activeSubs = $activeShared + $activeIsp;
        $expiringSoon = User::whereBetween('plan_expired_at', [now(), $soon])->count()
            + SubscriptionInstance::where('status', SubscriptionInstance::STATUS_ACTIVE)->whereBetween('expired_at', [now(), $soon])->count();

        // 待支付订单（未收款）+ 余额负债（欠客户的 store credit）
        $pendingOrders = Order::where('status', Order::STATUS_PENDING)->count();
        $pendingOrderAmount = (int) Order::where('status', Order::STATUS_PENDING)->sum('total_amount');
        $balanceLiability = (int) User::sum('balance');

        // 节点在线（5 分钟内有心跳）
        $serversTotal = Server::count();
        $serversOnline = Server::where('last_seen_at', '>', now()->subMinutes(5))->count();

        // 收入环比：今日 vs 昨日、本月 vs 上月
        $yesterdayRevenue = (int) Order::where('status', Order::STATUS_PAID)
            ->whereBetween('paid_at', [now()->subDay()->startOfDay(), $today])->sum('total_amount');
        $lastMonthRevenue = (int) Order::where('status', Order::STATUS_PAID)
            ->whereBetween('paid_at', [now()->subMonthNoOverflow()->startOfMonth(), $monthStart])->sum('total_amount');
        $dayChange = $this->pctChange($todayRevenue, $yesterdayRevenue);
        $monthChange = $this->pctChange($monthRevenue, $lastMonthRevenue);

        // 收入概览：最近 30 天，按天汇总订单金额 + 佣金发放金额
        $revenueByDay = Order::where('status', Order::STATUS_PAID)
            ->where('paid_at', '>=', $chartStart)
            ->selectRaw('DATE(paid_at) as day, SUM(total_amount) as amount')
            ->groupBy('day')
            ->pluck('amount', 'day');

        $commissionByDay = Commission::where('created_at', '>=', $chartStart)
            ->selectRaw('DATE(created_at) as day, SUM(amount) as amount')
            ->groupBy('day')
            ->pluck('amount', 'day');

        $chartDays = collect(range(0, 29))->map(fn ($i) => $chartStart->copy()->addDays($i)->toDateString());
        $chartRevenue = $chartDays->map(fn ($d) => (int) ($revenueByDay[$d] ?? 0))->values();
        $chartCommission = $chartDays->map(fn ($d) => (int) ($commissionByDay[$d] ?? 0))->values();

        // 节点 / 用户流量排行（今日）
        $nodeRanking = TrafficLog::whereDate('date', now()->toDateString())
            ->whereNotNull('server_id')
            ->selectRaw('server_id, SUM(upload_bytes + download_bytes) as total')
            ->groupBy('server_id')
            ->orderByDesc('total')
            ->with('server')
            ->limit(5)
            ->get();

        $userRanking = TrafficLog::whereDate('date', now()->toDateString())
            ->selectRaw('user_id, SUM(upload_bytes + download_bytes) as total')
            ->groupBy('user_id')
            ->orderByDesc('total')
            ->with('user')
            ->limit(5)
            ->get();
        $maxNodeTraffic = max(1, (int) ($nodeRanking->max('total') ?? 0));
        $maxUserTraffic = max(1, (int) ($userRanking->max('total') ?? 0));

        // 队列状态 —— 没有 Horizon/Redis，靠 jobs/failed_jobs 表 + 一个真实的
        // 「worker 心跳」（每处理完一个任务就更新一次，见 AppServiceProvider）
        // 来判断，不编造"活跃进程数"这类我们其实无法得知的数字。
        $pendingJobs = DB::table('jobs')->count();
        $oldestJobCreatedAt = DB::table('jobs')->min('created_at');
        $waitSeconds = $oldestJobCreatedAt ? max(0, now()->timestamp - $oldestJobCreatedAt) : 0;
        $failedLast7Days = DB::table('failed_jobs')->where('failed_at', '>=', now()->subDays(7))->count();
        $totalFailedJobs = DB::table('failed_jobs')->count();
        $lastSeenAt = Setting::get('queue_worker_last_seen_at');
        $workerRecentlyActive = $lastSeenAt && now()->diffInMinutes($lastSeenAt) < 5;
        $queueHealthy = $pendingJobs === 0 || $workerRecentlyActive;

        // 分站 economics. An agent's "收入" is retail they collected, but what
        // they actually keep is retail minus the wholesale the platform debits
        // on delivery — showing revenue alone overstates their margin several
        // times over. And until now they had no way at all to see their 进货余额:
        // the only signal it had run out was a customer's order failing.
        $agentDiscount = null;
        $wholesaleBalance = null;
        $monthWholesale = null;
        $monthProfit = null;
        $cardHeadroom = null;

        if ($tenant = tenant()) {
            $agentDiscount = (int) ($tenant->agent_discount ?? 0);
            $wholesaleBalance = app(\App\Services\WholesaleService::class)->balance($tenant->getTenantKey());
            // 卖掉的额度按面额消耗，但那些额度当初是打折买来的——差价就是利润。
            $monthWholesale = (int) round($monthRevenue * (100 - $agentDiscount) / 100);
            $monthProfit = $monthRevenue - $monthWholesale;
            $cardHeadroom = app(\App\Services\WholesaleService::class)->giftCardHeadroom($tenant);
        }

        return [
            'agentDiscount' => $agentDiscount,
            'wholesaleBalance' => $wholesaleBalance,
            'monthWholesale' => $monthWholesale,
            'monthProfit' => $monthProfit,
            'cardHeadroom' => $cardHeadroom,
            'todayRevenue' => $todayRevenue,
            'monthRevenue' => $monthRevenue,
            'dayChange' => $dayChange,
            'monthChange' => $monthChange,
            'activeSubs' => $activeSubs,
            'activeShared' => $activeShared,
            'activeIsp' => $activeIsp,
            'expiringSoon' => $expiringSoon,
            'pendingOrders' => $pendingOrders,
            'pendingOrderAmount' => $pendingOrderAmount,
            'balanceLiability' => $balanceLiability,
            'serversTotal' => $serversTotal,
            'serversOnline' => $serversOnline,
            'pendingTickets' => $pendingTickets,
            'pendingCommission' => $pendingCommission,
            'monthNewUsers' => $monthNewUsers,
            'totalUsers' => $totalUsers,
            'monthUpload' => $monthUpload,
            'monthDownload' => $monthDownload,
            'chartDays' => $chartDays,
            'chartRevenue' => $chartRevenue,
            'chartCommission' => $chartCommission,
            'nodeRanking' => $nodeRanking,
            'userRanking' => $userRanking,
            'maxNodeTraffic' => $maxNodeTraffic,
            'maxUserTraffic' => $maxUserTraffic,
            'pendingJobs' => $pendingJobs,
            'waitSeconds' => $waitSeconds,
            'failedLast7Days' => $failedLast7Days,
            'totalFailedJobs' => $totalFailedJobs,
            'lastSeenAt' => $lastSeenAt,
            'queueHealthy' => $queueHealthy,
        ];
    }
};
?>

<div class="space-y-6">
    <h1 class="text-xl font-semibold text-gray-900 dark:text-gray-100">仪表盘</h1>

    {{-- 分站：进货余额与真实利润。余额是这个店能不能继续开单的唯一决定因素，
         放在最上面；下面的「收入」是零售额，不是到手的钱。 --}}
    @if ($wholesaleBalance !== null)
        <div class="grid grid-cols-1 lg:grid-cols-4 gap-4">
            <div class="border rounded-xl p-4 {{ $wholesaleBalance <= 0 ? 'border-red-300 dark:border-red-800 bg-red-50 dark:bg-red-500/10' : ($wholesaleBalance < 5000 ? 'border-amber-300 dark:border-amber-800 bg-amber-50 dark:bg-amber-500/10' : 'border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900') }}">
                <div class="text-xs text-gray-500 dark:text-gray-400 mb-1">进货余额（还能卖）</div>
                <div class="text-xl font-semibold {{ $wholesaleBalance <= 0 ? 'text-red-600 dark:text-red-400' : 'text-gray-900 dark:text-gray-100' }}">¥{{ number_format($wholesaleBalance / 100, 2) }}</div>
                <div class="text-xs mt-0.5 {{ $wholesaleBalance <= 0 ? 'text-red-600 dark:text-red-400' : 'text-gray-400 dark:text-gray-500' }}">
                    @if ($wholesaleBalance <= 0)
                        已用完，客户无法开通——请联系站长充值
                    @elseif ($wholesaleBalance < 5000)
                        余额偏低，建议尽快充值
                    @else
                        用于给客户开通，不足时无法交付
                    @endif
                </div>
            </div>
            <div class="border border-gray-200 dark:border-gray-800 rounded-xl p-4 bg-white dark:bg-gray-900">
                <div class="text-xs text-gray-500 dark:text-gray-400 mb-1">本月净利</div>
                <div class="text-xl font-semibold text-teal-600 dark:text-teal-400">¥{{ number_format($monthProfit / 100, 2) }}</div>
                <div class="text-xs mt-0.5 text-gray-400 dark:text-gray-500">零售 ¥{{ number_format($monthRevenue / 100, 2) }} − 额度成本 ¥{{ number_format($monthWholesale / 100, 2) }}</div>
            </div>
            <div class="border border-gray-200 dark:border-gray-800 rounded-xl p-4 bg-white dark:bg-gray-900">
                <div class="text-xs text-gray-500 dark:text-gray-400 mb-1">你的进货折扣</div>
                <div class="text-xl font-semibold text-gray-900 dark:text-gray-100">{{ $agentDiscount }}% off</div>
                <div class="text-xs mt-0.5 text-gray-400 dark:text-gray-500">¥{{ number_format(100 - $agentDiscount, 0) }} 买 ¥100 额度，卖满即赚 ¥{{ $agentDiscount }}</div>
            </div>
            {{-- 卡密额度跟着进货余额走，卖卡收现金前得先知道自己还能开多少。 --}}
            <div class="border border-gray-200 dark:border-gray-800 rounded-xl p-4 bg-white dark:bg-gray-900">
                <div class="text-xs text-gray-500 dark:text-gray-400 mb-1">还可发行卡密</div>
                <div class="text-xl font-semibold {{ $cardHeadroom > 0 ? 'text-gray-900 dark:text-gray-100' : 'text-red-600 dark:text-red-400' }}">¥{{ number_format($cardHeadroom / 100, 2) }}</div>
                <div class="text-xs mt-0.5 text-gray-400 dark:text-gray-500">未核销的卡占额度，普通订单也吃同一池</div>
            </div>
        </div>
    @endif

    {{-- 统计卡片 --}}
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="border border-gray-200 dark:border-gray-800 rounded-xl p-4 bg-white dark:bg-gray-900">
            <div class="text-xs text-gray-500 dark:text-gray-400 mb-1">今日收入</div>
            <div class="text-xl font-semibold text-gray-900 dark:text-gray-100">¥{{ number_format($todayRevenue / 100, 2) }}</div>
            @if ($dayChange !== null)
                <div class="text-xs mt-0.5 {{ $dayChange >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-500 dark:text-red-400' }}">{{ $dayChange >= 0 ? '↑' : '↓' }} {{ abs($dayChange) }}% 较昨日</div>
            @else
                <div class="text-xs mt-0.5 text-gray-400 dark:text-gray-500">较昨日 —</div>
            @endif
        </div>
        <div class="border border-gray-200 dark:border-gray-800 rounded-xl p-4 bg-white dark:bg-gray-900">
            <div class="text-xs text-gray-500 dark:text-gray-400 mb-1">月收入</div>
            <div class="text-xl font-semibold text-gray-900 dark:text-gray-100">¥{{ number_format($monthRevenue / 100, 2) }}</div>
            @if ($monthChange !== null)
                <div class="text-xs mt-0.5 {{ $monthChange >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-500 dark:text-red-400' }}">{{ $monthChange >= 0 ? '↑' : '↓' }} {{ abs($monthChange) }}% 较上月</div>
            @else
                <div class="text-xs mt-0.5 text-gray-400 dark:text-gray-500">较上月 —</div>
            @endif
        </div>
        <div class="border border-gray-200 dark:border-gray-800 rounded-xl p-4 bg-white dark:bg-gray-900">
            <div class="text-xs text-gray-500 dark:text-gray-400 mb-1">待处理工单</div>
            <div class="text-xl font-semibold text-gray-900 dark:text-gray-100">{{ $pendingTickets }}</div>
        </div>
        <div class="border border-gray-200 dark:border-gray-800 rounded-xl p-4 bg-white dark:bg-gray-900">
            <div class="text-xs text-gray-500 dark:text-gray-400 mb-1">待确认佣金</div>
            <div class="text-xl font-semibold text-gray-900 dark:text-gray-100">¥{{ number_format($pendingCommission / 100, 2) }}</div>
        </div>
        <div class="border border-gray-200 dark:border-gray-800 rounded-xl p-4 bg-white dark:bg-gray-900">
            <div class="text-xs text-gray-500 dark:text-gray-400 mb-1">月新增用户</div>
            <div class="text-xl font-semibold text-gray-900 dark:text-gray-100">{{ $monthNewUsers }}</div>
        </div>
        <div class="border border-gray-200 dark:border-gray-800 rounded-xl p-4 bg-white dark:bg-gray-900">
            <div class="text-xs text-gray-500 dark:text-gray-400 mb-1">总用户</div>
            <div class="text-xl font-semibold text-gray-900 dark:text-gray-100">{{ $totalUsers }}</div>
        </div>
        <div class="border border-gray-200 dark:border-gray-800 rounded-xl p-4 bg-white dark:bg-gray-900">
            <div class="text-xs text-gray-500 dark:text-gray-400 mb-1">月上传</div>
            <div class="text-xl font-semibold text-gray-900 dark:text-gray-100">{{ $this->formatBytesShort($monthUpload) }}</div>
        </div>
        <div class="border border-gray-200 dark:border-gray-800 rounded-xl p-4 bg-white dark:bg-gray-900">
            <div class="text-xs text-gray-500 dark:text-gray-400 mb-1">月下载</div>
            <div class="text-xl font-semibold text-gray-900 dark:text-gray-100">{{ $this->formatBytesShort($monthDownload) }}</div>
        </div>
    </div>

    {{-- 运营指标 --}}
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="border border-gray-200 dark:border-gray-800 rounded-xl p-4 bg-white dark:bg-gray-900">
            <div class="text-xs text-gray-500 dark:text-gray-400 mb-1">活跃订阅</div>
            <div class="text-xl font-semibold text-gray-900 dark:text-gray-100">{{ $activeSubs }}</div>
            <div class="text-xs mt-0.5 text-gray-400 dark:text-gray-500">共享 {{ $activeShared }} · ISP {{ $activeIsp }}</div>
        </div>
        <div class="border border-gray-200 dark:border-gray-800 rounded-xl p-4 bg-white dark:bg-gray-900">
            <div class="text-xs text-gray-500 dark:text-gray-400 mb-1">7 天内到期</div>
            <div class="text-xl font-semibold {{ $expiringSoon > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-gray-900 dark:text-gray-100' }}">{{ $expiringSoon }}</div>
            <div class="text-xs mt-0.5 text-gray-400 dark:text-gray-500">需关注续费</div>
        </div>
        <div class="border border-gray-200 dark:border-gray-800 rounded-xl p-4 bg-white dark:bg-gray-900">
            <div class="text-xs text-gray-500 dark:text-gray-400 mb-1">待支付订单</div>
            <div class="text-xl font-semibold text-gray-900 dark:text-gray-100">{{ $pendingOrders }}</div>
            <div class="text-xs mt-0.5 text-gray-400 dark:text-gray-500">合计 ¥{{ number_format($pendingOrderAmount / 100, 2) }}</div>
        </div>
        <div class="border border-gray-200 dark:border-gray-800 rounded-xl p-4 bg-white dark:bg-gray-900">
            <div class="text-xs text-gray-500 dark:text-gray-400 mb-1">余额负债</div>
            <div class="text-xl font-semibold text-gray-900 dark:text-gray-100">¥{{ number_format($balanceLiability / 100, 2) }}</div>
            {{-- 分站看到的是自己和站长之间的账：客户充值的钱代理已经收了，但只有
                 客户真花掉、订单交付时才结算进货。"store credit" 对代理没意义。 --}}
            <div class="text-xs mt-0.5 text-gray-400 dark:text-gray-500">
                @if ($wholesaleBalance !== null)
                    客户已充值未消费，消费时才扣进货
                @else
                    欠客户的 store credit
                @endif
            </div>
        </div>
    </div>

    {{-- 收入概览 --}}
    <div class="border border-gray-200 dark:border-gray-800 rounded-xl p-5 bg-white dark:bg-gray-900">
        <div class="flex items-center justify-between mb-4">
            <div>
                <h2 class="text-sm font-medium text-gray-700 dark:text-gray-300">收入概览</h2>
                <p class="text-xs text-gray-400 dark:text-gray-500 mt-0.5">{{ $chartDays->first() }} 至 {{ $chartDays->last() }}</p>
            </div>
            <div class="text-xs text-gray-500 dark:text-gray-400 flex items-center gap-4">
                <span class="flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full bg-gray-900 dark:bg-gray-100"></span>订单金额</span>
                <span class="flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full bg-teal-400"></span>佣金金额</span>
            </div>
        </div>

        @php
            $maxAmount = max(1, $chartRevenue->max(), $chartCommission->max());
            $svgWidth = 700;
            $svgHeight = 160;
            $pointCount = $chartDays->count();
            $stepX = $pointCount > 1 ? $svgWidth / ($pointCount - 1) : $svgWidth;
            $toPoints = function ($values) use ($stepX, $svgHeight, $maxAmount) {
                return collect($values)->map(function ($v, $i) use ($stepX, $svgHeight, $maxAmount) {
                    $x = round($i * $stepX, 1);
                    $y = round($svgHeight - ($v / $maxAmount) * ($svgHeight - 10) - 5, 1);
                    return "{$x},{$y}";
                })->implode(' ');
            };
            $revenuePoints = $toPoints($chartRevenue);
            $commissionPoints = $toPoints($chartCommission);
        @endphp

        <svg viewBox="0 0 {{ $svgWidth }} {{ $svgHeight }}" class="w-full h-40" preserveAspectRatio="none">
            <polyline points="{{ $revenuePoints }}" fill="none" stroke="currentColor" stroke-width="2" class="text-gray-900 dark:text-gray-100" />
            <polyline points="{{ $commissionPoints }}" fill="none" stroke="#2dd4bf" stroke-width="2" />
        </svg>

        <div class="flex items-center gap-6 mt-3 text-sm">
            <div>
                <div class="text-xs text-gray-400 dark:text-gray-500">总收入</div>
                <div class="font-semibold text-gray-900 dark:text-gray-100">¥{{ number_format($chartRevenue->sum() / 100, 2) }}</div>
            </div>
            <div>
                <div class="text-xs text-gray-400 dark:text-gray-500">总佣金</div>
                <div class="font-semibold text-gray-900 dark:text-gray-100">¥{{ number_format($chartCommission->sum() / 100, 2) }}</div>
            </div>
        </div>
    </div>

    {{-- 流量排行 --}}
    <div class="grid md:grid-cols-2 gap-4">
        <div class="border border-gray-200 dark:border-gray-800 rounded-xl p-5 bg-white dark:bg-gray-900">
            <h2 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">节点流量排行（今天）</h2>
            @forelse ($nodeRanking as $row)
                <div class="mb-3">
                    <div class="flex items-center justify-between text-xs mb-1">
                        <span class="text-gray-700 dark:text-gray-300">{{ $row->server->name ?? '未知节点' }}</span>
                        <span class="text-gray-500 dark:text-gray-400">{{ $this->formatBytesShort((int) $row->total) }}</span>
                    </div>
                    <div class="h-1.5 bg-gray-100 dark:bg-gray-800 rounded-full overflow-hidden">
                        <div class="h-full bg-gray-900 dark:bg-gray-100" style="width: {{ round($row->total / $maxNodeTraffic * 100) }}%"></div>
                    </div>
                </div>
            @empty
                <p class="text-sm text-gray-400 dark:text-gray-500">今天还没有流量数据。</p>
            @endforelse
        </div>

        <div class="border border-gray-200 dark:border-gray-800 rounded-xl p-5 bg-white dark:bg-gray-900">
            <h2 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">用户流量排行（今天）</h2>
            @forelse ($userRanking as $row)
                <div class="mb-3">
                    <div class="flex items-center justify-between text-xs mb-1">
                        <span class="text-gray-700 dark:text-gray-300">{{ $row->user->email ?? '未知用户' }}</span>
                        <span class="text-gray-500 dark:text-gray-400">{{ $this->formatBytesShort((int) $row->total) }}</span>
                    </div>
                    <div class="h-1.5 bg-gray-100 dark:bg-gray-800 rounded-full overflow-hidden">
                        <div class="h-full bg-gray-900 dark:bg-gray-100" style="width: {{ round($row->total / $maxUserTraffic * 100) }}%"></div>
                    </div>
                </div>
            @empty
                <p class="text-sm text-gray-400 dark:text-gray-500">今天还没有流量数据。</p>
            @endforelse
        </div>
    </div>

    {{-- 队列状态 —— 平台运维，分站不显示：节点数量和队列健康都是平台的基础设施，
         代理既无从处理，也不该知道平台跑着几个节点、队列有没有堵。 --}}
    @unless (tenant())
    <div class="border border-gray-200 dark:border-gray-800 rounded-xl p-5 bg-white dark:bg-gray-900">
        <div class="flex items-center justify-between mb-4">
            <div>
                <h2 class="text-sm font-medium text-gray-700 dark:text-gray-300">系统状态</h2>
                <p class="text-xs text-gray-400 dark:text-gray-500 mt-0.5">静态 ISP 开通任务靠队列 worker 处理，这里能看出它是不是还活着。节点在线基于 5 分钟内心跳。</p>
            </div>
            <div class="flex items-center gap-2">
                <span class="text-xs font-medium rounded-full px-2.5 py-1 {{ $serversTotal > 0 && $serversOnline === $serversTotal ? 'bg-green-50 dark:bg-green-500/10 text-green-700 dark:text-green-400' : ($serversOnline > 0 ? 'bg-amber-50 dark:bg-amber-500/10 text-amber-700 dark:text-amber-400' : 'bg-gray-100 dark:bg-gray-800 text-gray-500') }}">
                    节点在线 {{ $serversOnline }}/{{ $serversTotal }}
                </span>
                <span class="text-xs font-medium rounded-full px-2.5 py-1 {{ $queueHealthy ? 'bg-green-50 dark:bg-green-500/10 text-green-700 dark:text-green-400' : 'bg-amber-50 dark:bg-amber-500/10 text-amber-700 dark:text-amber-400' }}">
                    队列{{ $queueHealthy ? '正常' : '需要关注' }}
                </span>
            </div>
        </div>

        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
            <div>
                <div class="text-xs text-gray-400 dark:text-gray-500 mb-1">待处理任务数</div>
                <div class="font-semibold text-gray-900 dark:text-gray-100">{{ $pendingJobs }}</div>
            </div>
            <div>
                <div class="text-xs text-gray-400 dark:text-gray-500 mb-1">最早任务等待</div>
                <div class="font-semibold text-gray-900 dark:text-gray-100">{{ $pendingJobs > 0 ? $this->formatDurationShort($waitSeconds) : '-' }}</div>
            </div>
            <div>
                <div class="text-xs text-gray-400 dark:text-gray-500 mb-1">7 日失败任务</div>
                <div class="font-semibold text-gray-900 dark:text-gray-100">{{ $failedLast7Days }}</div>
            </div>
            <div>
                <div class="text-xs text-gray-400 dark:text-gray-500 mb-1">历史失败总数</div>
                <div class="font-semibold text-gray-900 dark:text-gray-100">{{ $totalFailedJobs }}</div>
            </div>
        </div>

        <p class="text-xs text-gray-400 dark:text-gray-500 mt-4">
            {{ $lastSeenAt ? '最后一次处理任务：' . \Illuminate\Support\Carbon::parse($lastSeenAt)->diffForHumans() : '还没有任务被处理过——如果一直是这样，检查一下 queue worker 是不是没启动（`php artisan queue:work`）。' }}
        </p>
    </div>
    @endunless
</div>
