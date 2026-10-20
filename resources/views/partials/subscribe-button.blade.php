{{-- 一键订阅按钮 + 导入弹窗（扫码 / 复制 / 各客户端深链）。参数：$url（可拉取的 HTTP 订阅地址）、$siteName、可选 $nodeLinks（原始节点分享链接数组，用于「复制节点链接」直接粘贴导入）、$buttonClass / $buttonLabel。 --}}
<div
    x-data="{
        copied: false,
        nodesCopied: false,
        oneClickOpen: false,
        qrOpen: false,
        url: {{ json_encode($url) }},
        nodeLinks: {{ json_encode(array_values($nodeLinks ?? [])) }},
        siteName: {{ json_encode($siteName ?? config('app.name')) }},
        copyUrl() { copyToClipboard(this.url); this.copied = true; setTimeout(() => this.copied = false, 1500); },
        copyNodeLinks() { copyToClipboard(this.nodeLinks.join('\n')); this.nodesCopied = true; setTimeout(() => this.nodesCopied = false, 1500); },
        openQr() { this.qrOpen = true; this.$nextTick(() => window.renderQrCode(this.$refs.qrCanvas, this.url)); },
        clashUrl() { return 'clash://install-config?url=' + encodeURIComponent(this.url); },
        hiddifyUrl() { return 'hiddify://install-config?url=' + encodeURIComponent(this.url); },
        singboxUrl() { return 'sing-box://import-remote-profile?url=' + encodeURIComponent(this.url); },
        shadowrocketUrl() { return 'shadowrocket://add/sub://' + btoa(this.url) + '?remark=' + encodeURIComponent(this.siteName); },
        quantumultXUrl() { return 'quantumult-x://add-resource?remote-resource=' + encodeURIComponent(JSON.stringify({ server_remote: [this.url + ', tag=' + this.siteName] })); },
        surgeUrl() { return 'surge:///install-config?url=' + encodeURIComponent(this.url); },
        stashUrl() { return 'stash://install-config?url=' + encodeURIComponent(this.url); },
    }"
    class="inline-block"
>
    <button
        type="button"
        @click="oneClickOpen = true"
        class="{{ $buttonClass ?? 'text-sm font-medium bg-white text-gray-900 rounded-lg px-4 py-2' }}"
    >{{ $buttonLabel ?? '一键订阅' }}</button>

    <div
        x-show="oneClickOpen"
        x-cloak
        @keydown.escape.window="oneClickOpen = false; qrOpen = false"
        class="fixed inset-0 z-50 flex items-center justify-center p-4"
    >
        <div @click="oneClickOpen = false; qrOpen = false" class="absolute inset-0 bg-black/50"></div>

        <div class="relative bg-white dark:bg-gray-900 rounded-xl w-full max-w-sm max-h-[85vh] overflow-y-auto shadow-xl text-left">
            <div x-show="!qrOpen">
                <div class="divide-y divide-gray-100 dark:divide-gray-800">
                    <button type="button" @click="openQr()" class="w-full flex items-center gap-3 px-5 py-3.5 hover:bg-gray-50 dark:hover:bg-gray-800/60 text-left">
                        @include('partials.icon', ['name' => 'qrcode'])
                        <span class="text-sm text-gray-800 dark:text-gray-200">扫描二维码订阅</span>
                    </button>
                    <button type="button" @click="copyUrl()" class="w-full flex items-center gap-3 px-5 py-3.5 hover:bg-gray-50 dark:hover:bg-gray-800/60 text-left">
                        @include('partials.icon', ['name' => 'copy'])
                        <span class="text-sm text-gray-800 dark:text-gray-200" x-text="copied ? '已复制' : '复制订阅地址'"></span>
                    </button>
                    {{-- 兜底：客户端拉取订阅失败（本地网络干扰直连）时，复制原始节点链接，
                         在客户端「从剪贴板导入」即可，无需拉取任何 URL。--}}
                    <button type="button" x-show="nodeLinks.length" @click="copyNodeLinks()" class="w-full flex items-start gap-3 px-5 py-3.5 hover:bg-gray-50 dark:hover:bg-gray-800/60 text-left">
                        @include('partials.icon', ['name' => 'copy'])
                        <span>
                            <span class="block text-sm text-gray-800 dark:text-gray-200" x-text="nodesCopied ? '已复制' : '复制节点链接'"></span>
                            <span class="block text-xs text-gray-400 dark:text-gray-500 mt-0.5">导入订阅失败时用：复制后在客户端「从剪贴板导入」</span>
                        </span>
                    </button>
                    <a :href="clashUrl()" class="flex items-center gap-3 px-5 py-3.5 hover:bg-gray-50 dark:hover:bg-gray-800/60">
                        @include('partials.icon', ['name' => 'box'])
                        <span class="text-sm text-gray-800 dark:text-gray-200">导入到 Clash Meta</span>
                    </a>
                    <a :href="hiddifyUrl()" class="flex items-center gap-3 px-5 py-3.5 hover:bg-gray-50 dark:hover:bg-gray-800/60">
                        @include('partials.icon', ['name' => 'box'])
                        <span class="text-sm text-gray-800 dark:text-gray-200">导入到 Hiddify</span>
                    </a>
                    <a :href="singboxUrl()" class="flex items-center gap-3 px-5 py-3.5 hover:bg-gray-50 dark:hover:bg-gray-800/60">
                        @include('partials.icon', ['name' => 'box'])
                        <span class="text-sm text-gray-800 dark:text-gray-200">导入到 SingBox</span>
                    </a>
                    <a :href="shadowrocketUrl()" class="flex items-center gap-3 px-5 py-3.5 hover:bg-gray-50 dark:hover:bg-gray-800/60">
                        @include('partials.icon', ['name' => 'box'])
                        <span class="text-sm text-gray-800 dark:text-gray-200">导入到 Shadowrocket</span>
                    </a>
                    <a :href="quantumultXUrl()" class="flex items-center gap-3 px-5 py-3.5 hover:bg-gray-50 dark:hover:bg-gray-800/60">
                        @include('partials.icon', ['name' => 'box'])
                        <span class="text-sm text-gray-800 dark:text-gray-200">导入到 QuantumultX</span>
                    </a>
                    <a :href="surgeUrl()" class="flex items-center gap-3 px-5 py-3.5 hover:bg-gray-50 dark:hover:bg-gray-800/60">
                        @include('partials.icon', ['name' => 'box'])
                        <span class="text-sm text-gray-800 dark:text-gray-200">导入到 Surge</span>
                    </a>
                    <a :href="stashUrl()" class="flex items-center gap-3 px-5 py-3.5 hover:bg-gray-50 dark:hover:bg-gray-800/60">
                        @include('partials.icon', ['name' => 'box'])
                        <span class="text-sm text-gray-800 dark:text-gray-200">导入到 Stash</span>
                    </a>
                </div>
            </div>

            <div x-show="qrOpen" class="p-6 flex flex-col items-center gap-4">
                <canvas x-ref="qrCanvas"></canvas>
                <button type="button" @click="qrOpen = false" class="text-sm text-gray-500 dark:text-gray-400">返回</button>
            </div>
        </div>
    </div>
</div>
