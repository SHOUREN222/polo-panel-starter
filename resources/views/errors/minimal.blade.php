@php
    // Error pages must render even when the DB is unreachable (that may be the
    // very cause of a 500) — so every Setting read is guarded with a fallback.
    try { $siteName = \App\Models\Setting::get('site_name', config('app.name')); } catch (\Throwable) { $siteName = config('app.name'); }
    try { $brand = \App\Models\Setting::get('theme_primary_color', '#0d9488'); } catch (\Throwable) { $brand = '#0d9488'; }
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>@yield('code') · {{ $siteName }}</title>
        <script>
            if (localStorage.getItem('darkMode') === 'true'
                || (!('darkMode' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
                document.documentElement.classList.add('dark');
            }
        </script>
        @vite(['resources/css/app.css'])
        <style>:root { --brand: {{ $brand }}; }</style>
    </head>
    <body class="bg-gray-50 dark:bg-gray-950 text-gray-900 dark:text-gray-100 min-h-screen flex flex-col">
        <div class="flex-1 flex items-center justify-center px-6 py-16">
            <div class="text-center max-w-md">
                <div class="text-7xl font-bold tracking-tight" style="color: var(--brand)">@yield('code')</div>
                <h1 class="mt-4 text-xl font-semibold text-gray-900 dark:text-gray-100">@yield('title')</h1>
                <p class="mt-2 text-sm text-gray-500 dark:text-gray-400 leading-relaxed">@yield('message')</p>
                <div class="mt-8">
                    <a href="/" class="inline-block text-sm bg-gray-900 dark:bg-gray-100 text-white dark:text-gray-900 rounded-lg px-5 py-2.5 font-medium">返回首页</a>
                </div>
                <p class="mt-10 text-xs text-gray-400 dark:text-gray-600">{{ $siteName }}</p>
            </div>
        </div>
    </body>
</html>
