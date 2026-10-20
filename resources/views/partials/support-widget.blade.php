{{-- 全站悬浮「联系客服」气泡。渠道由后台「系统设置 → 客服设置」配置，未填的自动不显示；
     没有任何渠道或未开启时整个组件不渲染。Telegram 优先用 tg:// 深链，t.me 被墙也能打开 App。 --}}
@php
    $supportContacts = \App\Support\SupportContacts::all();
    $supportNote = \App\Support\SupportContacts::note();
@endphp

@if (count($supportContacts))
    <div
        x-data="{ open: false, copiedIdx: null, qrSrc: null, qrLabel: '', copy(text, idx) { window.copyToClipboard(text); this.copiedIdx = idx; setTimeout(() => { if (this.copiedIdx === idx) this.copiedIdx = null }, 1500) } }"
        class="fixed bottom-5 right-5 z-50 flex flex-col items-end gap-3"
    >
        {{-- 面板 --}}
        <div
            x-show="open"
            x-cloak
            x-transition:enter="transition ease-out duration-150"
            x-transition:enter-start="opacity-0 translate-y-2"
            x-transition:enter-end="opacity-100 translate-y-0"
            @click.outside="open = false"
            @keydown.escape.window="open = false"
            class="w-72 max-w-[calc(100vw-2.5rem)] bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-2xl shadow-xl overflow-hidden"
        >
            <div class="px-4 py-3 border-b border-gray-100 dark:border-gray-800 flex items-center justify-between">
                <div class="text-sm font-medium text-gray-900 dark:text-gray-100">联系客服</div>
                <button type="button" @click="open = false" class="p-1 -mr-1 rounded-lg text-gray-400 hover:text-gray-600 dark:hover:text-gray-300">
                    @include('partials.icon', ['name' => 'close'])
                </button>
            </div>

            @if ($supportNote !== '')
                <p class="px-4 pt-3 text-xs text-gray-400 dark:text-gray-500 whitespace-pre-line">{{ $supportNote }}</p>
            @endif

            <div class="p-2">
                @foreach ($supportContacts as $i => $c)
                    @if ($c['type'] === 'link')
                        <a
                            href="{{ $c['href'] }}"
                            target="_blank"
                            rel="noopener"
                            class="flex items-center gap-3 px-3 py-2.5 rounded-xl hover:bg-gray-50 dark:hover:bg-gray-800/60"
                        >
                            <span class="text-gray-500 dark:text-gray-400 shrink-0">@include('partials.icon', ['name' => $c['icon']])</span>
                            <span class="min-w-0 flex-1">
                                <span class="block text-sm text-gray-800 dark:text-gray-200">{{ $c['label'] }}</span>
                                <span class="block text-xs text-gray-400 dark:text-gray-500 truncate">{{ $c['value'] }}</span>
                            </span>
                            @if (! empty($c['copy']))
                                <button
                                    type="button"
                                    @click.prevent.stop="copy(@js($c['copy']), {{ $i }})"
                                    class="shrink-0 text-xs px-2 py-1 rounded-lg text-gray-400 hover:text-gray-700 dark:hover:text-gray-200"
                                >
                                    <span x-show="copiedIdx !== {{ $i }}">复制</span>
                                    <span x-show="copiedIdx === {{ $i }}" x-cloak class="text-green-600 dark:text-green-400">已复制</span>
                                </button>
                            @endif
                        </a>
                    @else
                        <div class="w-full flex items-center gap-3 px-3 py-2.5 rounded-xl hover:bg-gray-50 dark:hover:bg-gray-800/60">
                            <span class="text-gray-500 dark:text-gray-400 shrink-0">@include('partials.icon', ['name' => $c['icon']])</span>
                            <span class="min-w-0 flex-1">
                                <span class="block text-sm text-gray-800 dark:text-gray-200">{{ $c['label'] }}</span>
                                <span class="block text-xs text-gray-400 dark:text-gray-500 truncate font-mono">{{ $c['value'] }}</span>
                            </span>
                            <span class="shrink-0 flex items-center gap-1">
                                @if (! empty($c['qr']))
                                    <button
                                        type="button"
                                        @click="qrSrc = @js($c['qr']); qrLabel = @js($c['label'])"
                                        class="text-xs px-2 py-1 rounded-lg text-teal-700 dark:text-teal-400 hover:bg-teal-50 dark:hover:bg-teal-500/10"
                                    >扫码</button>
                                @endif
                                @if (! empty($c['copy']))
                                    <button
                                        type="button"
                                        @click="copy(@js($c['copy']), {{ $i }})"
                                        class="text-xs px-2 py-1 rounded-lg text-gray-400 hover:text-gray-700 dark:hover:text-gray-200"
                                    >
                                        <span x-show="copiedIdx !== {{ $i }}">复制</span>
                                        <span x-show="copiedIdx === {{ $i }}" x-cloak class="text-green-600 dark:text-green-400">已复制</span>
                                    </button>
                                @endif
                            </span>
                        </div>
                    @endif
                @endforeach
            </div>
        </div>

        {{-- 二维码放大层（微信/QQ 扫码） --}}
        <div
            x-show="qrSrc"
            x-cloak
            @click="qrSrc = null"
            @keydown.escape.window="qrSrc = null"
            class="fixed inset-0 z-[60] flex items-center justify-center p-4"
        >
            <div class="absolute inset-0 bg-black/60"></div>
            <div class="relative bg-white dark:bg-gray-900 rounded-2xl p-5 flex flex-col items-center gap-3 shadow-xl">
                <div class="text-sm font-medium text-gray-900 dark:text-gray-100" x-text="qrLabel + ' 扫码'"></div>
                <img :src="qrSrc" alt="二维码" class="w-56 h-56 object-contain rounded-lg bg-white">
                <div class="text-xs text-gray-400 dark:text-gray-500">用对应 App 扫码，或截图后从相册识别</div>
                <button type="button" @click="qrSrc = null" class="text-sm text-gray-500 dark:text-gray-400">关闭</button>
            </div>
        </div>

        {{-- 触发按钮（纯文字） --}}
        <button
            type="button"
            @click="open = !open"
            class="px-5 py-2.5 rounded-full bg-[var(--brand)] hover:brightness-110 text-white text-sm font-medium shadow-lg transition"
            aria-label="联系客服"
        >
            <span x-show="!open">联系客服</span>
            <span x-show="open" x-cloak>关闭</span>
        </button>
    </div>
@endif
