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
    <body class="bg-gray-50 dark:bg-gray-950 text-gray-900 dark:text-gray-100 min-h-screen">
        <nav class="bg-white dark:bg-gray-900 border-b border-gray-200 dark:border-gray-800">
            <div class="max-w-4xl mx-auto px-6 py-3">
                <a href="/login" class="flex items-center gap-2 font-semibold text-gray-900 dark:text-gray-100">
                    @if ($siteLogoUrl)
                        <img src="{{ $siteLogoUrl }}" alt="{{ $siteName }}" class="h-7 w-auto">
                    @else
                        {{ $siteName }}
                    @endif
                </a>
            </div>
        </nav>

        <main class="max-w-4xl mx-auto px-6 py-8">
            {{ $slot }}
        </main>

        @include('partials.support-widget')

        @livewireScripts
    </body>
</html>
