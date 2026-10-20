@php
    $icon = $icon ?? 'box';
@endphp

<div class="space-y-6">
    <div>
        <h1 class="text-xl font-semibold text-gray-900 dark:text-gray-100">{{ $title }}</h1>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{ $description }}</p>
    </div>

    <div class="border border-dashed border-gray-300 dark:border-gray-700 rounded-xl p-16 flex flex-col items-center justify-center text-center bg-white dark:bg-gray-900">
        <div class="w-12 h-12 rounded-full bg-gray-100 dark:bg-gray-800 flex items-center justify-center text-gray-400 dark:text-gray-500 mb-4">
            @include('partials.icon', ['name' => $icon])
        </div>
        <p class="text-sm font-medium text-gray-700 dark:text-gray-300">该功能开发中</p>
        <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">敬请期待，菜单入口已经先搭好了</p>
    </div>
</div>
