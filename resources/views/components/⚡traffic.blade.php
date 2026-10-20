<?php

use App\Models\TrafficLog;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app', ['title' => '流量明细'])] class extends Component
{
    use WithPagination;

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1073741824) {
            return round($bytes / 1073741824, 2) . ' GB';
        }
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 2) . ' MB';
        }
        if ($bytes >= 1024) {
            return round($bytes / 1024, 2) . ' KB';
        }

        return $bytes . ' B';
    }

    public function with(): array
    {
        // Daily totals (summed across a day's rows) for the trend chart, oldest→newest.
        $daily = TrafficLog::where('user_id', auth()->id())
            ->where('date', '>=', now()->subMonth())
            ->orderBy('date')
            ->get()
            ->groupBy(fn ($l) => $l->date->format('Y-m-d'))
            ->map(fn ($rows) => $rows->sum(fn ($r) => $r->totalBytes()));

        return [
            'daily' => $daily,
            'logs' => TrafficLog::where('user_id', auth()->id())
                ->where('date', '>=', now()->subMonth())
                ->orderByDesc('date')
                ->paginate(20),
        ];
    }
};
?>

<div class="space-y-6">
    <h1 class="text-xl font-semibold text-gray-900 dark:text-gray-100">流量明细</h1>

    <div class="text-sm text-gray-600 dark:text-gray-400 bg-gray-50 dark:bg-gray-800/60 border border-gray-200 dark:border-gray-800 rounded-lg p-3 flex items-center gap-2">
        @include('partials.icon', ['name' => 'search'])
        流量明细仅保留近一个月数据以供查询。
    </div>

    {{-- 近一个月每日流量趋势（每日总计求和）。单条数据、无需图例，标题即说明。 --}}
    @php
        $pts = [];
        $grid = [];
        $xTicks = [];
        if ($daily->count()) {
            $vals = array_map('intval', $daily->values()->all());
            $keys = $daily->keys()->all();
            $n = count($vals);
            $max = max(1, max($vals));
            $W = 800; $H = 240;
            $padL = 56; $padR = 16; $padT = 16; $padB = 30;
            $plotW = $W - $padL - $padR;
            $plotH = $H - $padT - $padB;
            $baseY = $padT + $plotH;
            foreach ($vals as $i => $v) {
                $x = $n === 1 ? $padL + $plotW / 2 : $padL + $plotW * $i / ($n - 1);
                $y = $padT + $plotH * (1 - $v / $max);
                $pts[] = [
                    'x' => round($x, 1),
                    'y' => round($y, 1),
                    'd' => $keys[$i],
                    'v' => $this->formatBytes($v),
                    'xPct' => round($x / $W * 100, 2),
                ];
            }
            $firstX = $pts[0]['x'];
            $lastX = $pts[$n - 1]['x'];
            $linePoints = implode(' ', array_map(fn ($p) => $p['x'] . ',' . $p['y'], $pts));
            $areaPath = "M {$firstX},{$baseY} L {$linePoints} L {$lastX},{$baseY} Z";
            foreach ([0, 0.25, 0.5, 0.75, 1] as $f) {
                $grid[] = ['y' => round($padT + $plotH * (1 - $f), 1), 'label' => $this->formatBytes((int) round($max * $f))];
            }
            // Thin x labels to ~6 to avoid collision.
            $step = max(1, (int) ceil($n / 6));
            for ($i = 0; $i < $n; $i += $step) {
                $xTicks[] = ['x' => $pts[$i]['x'], 'label' => substr($pts[$i]['d'], 5)]; // MM-DD
            }
        }
    @endphp

    @if (count($pts) >= 2)
        <div
            x-data="{ hover: null, pts: @js($pts) }"
            class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-xl p-5"
        >
            <div class="flex items-center justify-between mb-2 min-h-6">
                <h2 class="text-sm font-medium text-gray-700 dark:text-gray-300">近一个月流量趋势</h2>
                <div class="text-xs text-gray-400 dark:text-gray-500" x-show="hover !== null" x-cloak>
                    <span x-text="hover !== null ? pts[hover].d : ''"></span>
                    ·
                    <span class="font-medium text-gray-700 dark:text-gray-300" x-text="hover !== null ? pts[hover].v : ''"></span>
                </div>
            </div>

            <svg viewBox="0 0 {{ $W }} {{ $H }}" class="w-full select-none" style="height:auto" role="img" aria-label="近一个月每日流量趋势线形图">
                <defs>
                    <linearGradient id="trafficArea" x1="0" y1="0" x2="0" y2="1">
                        <stop offset="0%" stop-color="var(--brand)" stop-opacity="0.28" />
                        <stop offset="100%" stop-color="var(--brand)" stop-opacity="0" />
                    </linearGradient>
                </defs>

                {{-- 网格线 + Y 轴刻度（弱化） --}}
                @foreach ($grid as $g)
                    <line x1="{{ $padL }}" y1="{{ $g['y'] }}" x2="{{ $W - $padR }}" y2="{{ $g['y'] }}"
                          class="stroke-gray-100 dark:stroke-gray-800" stroke-width="1" vector-effect="non-scaling-stroke" />
                    <text x="{{ $padL - 8 }}" y="{{ $g['y'] + 3 }}" text-anchor="end"
                          class="fill-gray-400 dark:fill-gray-500" style="font-size:11px">{{ $g['label'] }}</text>
                @endforeach

                {{-- 面积 + 折线 --}}
                <path d="{{ $areaPath }}" fill="url(#trafficArea)" />
                <polyline points="{{ $linePoints }}" fill="none" stroke="var(--brand)" stroke-width="2"
                          stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke" />

                {{-- X 轴日期刻度 --}}
                @foreach ($xTicks as $t)
                    <text x="{{ $t['x'] }}" y="{{ $H - 10 }}" text-anchor="middle"
                          class="fill-gray-400 dark:fill-gray-500" style="font-size:11px">{{ $t['label'] }}</text>
                @endforeach

                {{-- 悬停：十字准星 + 高亮点（Alpine 绑定） --}}
                <line x-show="hover !== null" x-cloak
                      :x1="pts[hover]?.x" :x2="pts[hover]?.x" y1="{{ $padT }}" y2="{{ $baseY }}"
                      class="stroke-gray-300 dark:stroke-gray-600" stroke-width="1" stroke-dasharray="3 3" vector-effect="non-scaling-stroke" />
                <circle x-show="hover !== null" x-cloak :cx="pts[hover]?.x" :cy="pts[hover]?.y" r="4"
                        fill="var(--brand)" class="stroke-white dark:stroke-gray-900" stroke-width="2" />

                {{-- 透明命中区：整段高度，鼠标好悬停 --}}
                @foreach ($pts as $i => $p)
                    @php
                        $half = $n > 1 ? ($plotW / ($n - 1)) / 2 : $plotW / 2;
                        $rx = max($padL, $p['x'] - $half);
                        $rw = min($W - $padR, $p['x'] + $half) - $rx;
                    @endphp
                    <rect x="{{ round($rx, 1) }}" y="{{ $padT }}" width="{{ round($rw, 1) }}" height="{{ $plotH }}"
                          fill="transparent" @mouseenter="hover = {{ $i }}" @mouseleave="hover = null" />
                @endforeach
            </svg>
        </div>
    @endif

    <table class="w-full text-sm bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-xl overflow-hidden">
        <thead class="bg-gray-50 dark:bg-gray-800/60 text-gray-500 dark:text-gray-400 text-xs">
            <tr>
                <th class="text-left px-4 py-2">记录时间</th>
                <th class="text-left px-4 py-2">实际上行</th>
                <th class="text-left px-4 py-2">实际下行</th>
                <th class="text-left px-4 py-2">扣费倍率</th>
                <th class="text-left px-4 py-2" title="(实际上行 + 实际下行) × 扣费倍率">总计</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($logs as $log)
                <tr class="border-t border-gray-100 dark:border-gray-800">
                    <td class="px-4 py-2 text-gray-700 dark:text-gray-300">{{ $log->date->format('Y-m-d') }}</td>
                    <td class="px-4 py-2 text-gray-500 dark:text-gray-400">{{ $this->formatBytes($log->upload_bytes) }}</td>
                    <td class="px-4 py-2 text-gray-500 dark:text-gray-400">{{ $this->formatBytes($log->download_bytes) }}</td>
                    <td class="px-4 py-2">
                        <span class="text-xs bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-400 rounded-full px-2 py-1">{{ rtrim(rtrim($log->rate, '0'), '.') ?: '0' }}x</span>
                    </td>
                    <td class="px-4 py-2 text-gray-900 dark:text-gray-100">{{ $this->formatBytes($log->totalBytes()) }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="5" class="px-4 py-8 text-center text-gray-400 dark:text-gray-500">还没有流量记录</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    {{ $logs->links() }}
</div>
