@php
    $latestNotice = \App\Models\Notice::where('show', true)->orderBy('sort')->latest()->first();
@endphp

@if ($latestNotice)
    <div
        x-data="{ open: localStorage.getItem('dismissedNoticeId') !== '{{ $latestNotice->id }}' }"
        x-show="open"
        x-cloak
        class="bg-[var(--brand)] text-white px-4 md:px-6 py-2.5 flex items-center gap-3 text-sm"
    >
        @include('partials.icon', ['name' => 'megaphone'])
        <span class="font-medium shrink-0">{{ $latestNotice->title }}</span>
        <span class="text-white/80 truncate">{{ $latestNotice->content }}</span>
        <button
            type="button"
            @click="open = false; localStorage.setItem('dismissedNoticeId', '{{ $latestNotice->id }}')"
            class="ml-auto shrink-0 text-white/70 hover:text-white"
        >
            @include('partials.icon', ['name' => 'close'])
        </button>
    </div>
@endif
