<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">

        <title>{{ $title ?? '管理后台登录' }} · {{ \App\Models\Setting::get('site_name', config('app.name')) }}</title>

        @vite(['resources/css/app.css', 'resources/js/app.js'])

        @livewireStyles
    </head>
    <body class="bg-gray-950 text-gray-100 min-h-screen">
        <main class="max-w-4xl mx-auto px-6 py-8">
            {{ $slot }}
        </main>

        @livewireScripts
    </body>
</html>
