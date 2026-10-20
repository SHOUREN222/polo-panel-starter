@php
    $statusStyles = match ($status) {
        'active' => 'bg-emerald-400/15 text-emerald-300',
        'pending' => 'bg-amber-400/15 text-amber-300',
        'expired' => 'bg-rose-400/15 text-rose-300',
        default => 'bg-white/10 text-white/60',
    };
@endphp

{{-- 线路信息卡（只读）。导入订阅统一走上面那张「一键订阅」聚合卡。 --}}
<div class="rounded-2xl p-5 bg-gray-900 dark:bg-gray-950 border border-gray-800 text-white flex flex-col">
    <div class="flex items-center justify-between mb-3">
        <span class="text-xs font-medium px-2.5 py-1 rounded-full bg-white/10 text-white/80">{{ $kind }}</span>
        <span class="text-xs font-medium px-2.5 py-1 rounded-full {{ $statusStyles }}">{{ $statusLabel }}</span>
    </div>

    <h3 class="text-base font-semibold mb-4 leading-snug">{{ $title }}</h3>

    <div class="grid grid-cols-2 gap-x-4 gap-y-3 text-xs">
        @foreach ($meta as $label => $value)
            <div>
                <div class="text-white/40 mb-0.5">{{ $label }}</div>
                <div class="text-white/90 font-mono">{{ $value ?? '-' }}</div>
            </div>
        @endforeach
    </div>

    @isset($extra)
        <div class="flex flex-wrap gap-2 mt-4 pt-4 border-t border-white/10">
            <span class="text-xs text-white/40 self-center mr-1 w-full">{{ $extra['label'] }}</span>
            @foreach ($extra['periods'] as $period => $price)
                <button
                    wire:click="{{ $extra['action'] }}({{ $extra['id'] }}, '{{ $period }}')"
                    wire:loading.attr="disabled"
                    class="text-xs border border-white/15 rounded-lg px-3 py-1.5 text-white/80 hover:border-white/40"
                >
                    {{ $this->periodLabel($period) }} ¥{{ number_format($price / 100, 2) }}
                </button>
            @endforeach
        </div>
    @endisset
</div>
