<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">

        @php
            $siteName = \App\Models\Setting::get('site_name', config('app.name'));
            $siteLogoUrl = \App\Models\Setting::get('site_logo_url', '');
        @endphp
        <title>{{ $title ?? $siteName }}</title>

        <script>
            if (localStorage.getItem('darkMode') === 'true'
                || (!('darkMode' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
                document.documentElement.classList.add('dark');
            }
        </script>

        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @include('partials.theme-vars')

        @livewireStyles
    </head>
    <body
        x-data="{ dark: document.documentElement.classList.contains('dark'), mobileNav: false }"
        x-init="$watch('dark', v => { localStorage.setItem('darkMode', v); document.documentElement.classList.toggle('dark', v) })"
        class="bg-gray-50 dark:bg-gray-950 text-gray-900 dark:text-gray-100 min-h-screen flex"
    >
        <aside class="w-60 shrink-0 bg-white dark:bg-gray-900 border-r border-gray-200 dark:border-gray-800 hidden md:flex md:flex-col">
            <div class="h-16 flex items-center px-5 border-b border-gray-200 dark:border-gray-800">
                <a href="/" class="flex items-center gap-2 font-semibold text-gray-900 dark:text-gray-100">
                    @if ($siteLogoUrl)
                        <img src="{{ $siteLogoUrl }}" alt="{{ $siteName }}" class="h-7 w-auto">
                    @else
                        {{ $siteName }}
                    @endif
                </a>
            </div>

            @include('partials.customer-nav')
        </aside>

        {{-- 移动端导航抽屉：<md 时侧边栏隐藏，靠顶栏的汉堡按钮打开这个抽屉 --}}
        <div
            x-show="mobileNav"
            x-cloak
            @keydown.escape.window="mobileNav = false"
            class="fixed inset-0 z-40 md:hidden"
        >
            <div x-show="mobileNav" x-transition.opacity @click="mobileNav = false" class="absolute inset-0 bg-black/50"></div>
            <aside
                x-show="mobileNav"
                x-transition:enter="transition ease-out duration-200"
                x-transition:enter-start="-translate-x-full"
                x-transition:enter-end="translate-x-0"
                x-transition:leave="transition ease-in duration-150"
                x-transition:leave-start="translate-x-0"
                x-transition:leave-end="-translate-x-full"
                class="absolute inset-y-0 left-0 w-64 max-w-[80%] bg-white dark:bg-gray-900 border-r border-gray-200 dark:border-gray-800 flex flex-col"
            >
                <div class="h-16 flex items-center justify-between px-5 border-b border-gray-200 dark:border-gray-800">
                    <a href="/" class="flex items-center gap-2 font-semibold text-gray-900 dark:text-gray-100">
                        @if ($siteLogoUrl)
                            <img src="{{ $siteLogoUrl }}" alt="{{ $siteName }}" class="h-7 w-auto">
                        @else
                            {{ $siteName }}
                        @endif
                    </a>
                    <button type="button" @click="mobileNav = false" class="p-1.5 -mr-1.5 rounded-lg text-gray-500 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-800" aria-label="关闭菜单">
                        @include('partials.icon', ['name' => 'close'])
                    </button>
                </div>
                @include('partials.customer-nav')
            </aside>
        </div>

        <div class="flex-1 min-w-0">
            <header class="h-16 bg-white dark:bg-gray-900 border-b border-gray-200 dark:border-gray-800 flex items-center justify-between px-4 md:px-6 gap-4">
                {{-- 移动端汉堡：打开导航抽屉；桌面端有常驻侧边栏，故 md 以上隐藏 --}}
                <button
                    type="button"
                    @click="mobileNav = true"
                    class="md:hidden p-2 -ml-2 rounded-lg text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800"
                    aria-label="打开菜单"
                >
                    @include('partials.icon', ['name' => 'menu'])
                </button>

                <div class="flex items-center gap-3 ml-auto">
                    <button
                        type="button"
                        @click="dark = !dark"
                        class="p-2 rounded-lg text-gray-500 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-800"
                    >
                        <span x-show="!dark">@include('partials.icon', ['name' => 'moon'])</span>
                        <span x-show="dark" x-cloak>@include('partials.icon', ['name' => 'sun'])</span>
                    </button>

                    <div class="flex items-center gap-2 pl-3 border-l border-gray-200 dark:border-gray-700">
                        <div class="w-8 h-8 rounded-full bg-gray-900 dark:bg-gray-100 text-white dark:text-gray-900 flex items-center justify-center text-xs font-semibold">
                            {{ mb_substr(auth()->user()->name ?? '?', 0, 1) }}
                        </div>
                        <span class="text-sm text-gray-600 dark:text-gray-300 hidden sm:inline">{{ auth()->user()->email }}</span>
                    </div>
                </div>
            </header>

            @include('partials.announcement-bar')

            <main class="max-w-5xl mx-auto px-4 md:px-6 py-8">
                {{ $slot }}
            </main>
        </div>

        @include('partials.support-widget')
    </body>
</html>
