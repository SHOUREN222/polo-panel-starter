@php $name = $name ?? 'home'; @endphp

@switch($name)
    @case('home')
        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
            <polyline points="4 11 12 4 20 11" />
            <path d="M6 10.5V19a1 1 0 0 0 1 1h4v-5h2v5h4a1 1 0 0 0 1-1v-8.5" />
        </svg>
        @break

    @case('list')
        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
            <line x1="4" y1="6" x2="20" y2="6" />
            <line x1="4" y1="12" x2="20" y2="12" />
            <line x1="4" y1="18" x2="14" y2="18" />
        </svg>
        @break

    @case('globe')
        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="12" cy="12" r="8" />
            <ellipse cx="12" cy="12" rx="3.2" ry="8" />
            <line x1="4" y1="12" x2="20" y2="12" />
        </svg>
        @break

    @case('cart')
        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
            <path d="M4 5h2l2 11h9l2-7H7" />
            <circle cx="9.5" cy="19" r="1" fill="currentColor" stroke="none" />
            <circle cx="17" cy="19" r="1" fill="currentColor" stroke="none" />
        </svg>
        @break

    @case('logout')
        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
            <path d="M9 4H6a1 1 0 0 0-1 1v14a1 1 0 0 0 1 1h3" />
            <line x1="12" y1="12" x2="20" y2="12" />
            <polyline points="16 8 20 12 16 16" />
        </svg>
        @break

    @case('search')
        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="10.5" cy="10.5" r="6.5" />
            <line x1="20" y1="20" x2="15.3" y2="15.3" />
        </svg>
        @break

    @case('chevron-down')
        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
            <polyline points="6 9 12 15 18 9" />
        </svg>
        @break

    @case('moon')
        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="currentColor">
            <path d="M20 14.5A8.5 8.5 0 0 1 9.5 4a8.5 8.5 0 1 0 10.5 10.5Z" />
        </svg>
        @break

    @case('sun')
        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round">
            <circle cx="12" cy="12" r="4" />
            <line x1="12" y1="2" x2="12" y2="4.5" />
            <line x1="12" y1="19.5" x2="12" y2="22" />
            <line x1="2" y1="12" x2="4.5" y2="12" />
            <line x1="19.5" y1="12" x2="22" y2="12" />
            <line x1="4.9" y1="4.9" x2="6.6" y2="6.6" />
            <line x1="17.4" y1="17.4" x2="19.1" y2="19.1" />
            <line x1="4.9" y1="19.1" x2="6.6" y2="17.4" />
            <line x1="17.4" y1="6.6" x2="19.1" y2="4.9" />
        </svg>
        @break

    @case('shield')
        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
            <path d="M12 3l7 3v6c0 4.5-3 7.5-7 9-4-1.5-7-4.5-7-9V6l7-3Z" />
        </svg>
        @break

    @case('server')
        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
            <rect x="4" y="4" width="16" height="6" rx="1" />
            <rect x="4" y="14" width="16" height="6" rx="1" />
            <line x1="7" y1="7" x2="7.01" y2="7" />
            <line x1="7" y1="17" x2="7.01" y2="17" />
        </svg>
        @break

    @case('users')
        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="9" cy="8" r="3" />
            <path d="M3.5 20a5.5 5.5 0 0 1 11 0" />
            <circle cx="17" cy="9" r="2.3" />
            <path d="M15.5 12.2A4.5 4.5 0 0 1 20.5 16.5" />
        </svg>
        @break

    @case('tag')
        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
            <path d="M20 12.5 12.5 20 4 11.5V4h7.5L20 12.5Z" />
            <line x1="8" y1="8" x2="8.01" y2="8" />
        </svg>
        @break

    @case('sliders')
        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
            <line x1="5" y1="5" x2="5" y2="19" /><circle cx="5" cy="9" r="2" />
            <line x1="12" y1="5" x2="12" y2="19" /><circle cx="12" cy="15" r="2" />
            <line x1="19" y1="5" x2="19" y2="19" /><circle cx="19" cy="7" r="2" />
        </svg>
        @break

    @case('box')
        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
            <path d="M3.5 7.5 12 3l8.5 4.5v9L12 21l-8.5-4.5v-9Z" />
            <polyline points="3.5 7.5 12 12 20.5 7.5" />
            <line x1="12" y1="12" x2="12" y2="21" />
        </svg>
        @break

    @case('palette')
        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
            <path d="M12 3a9 8 0 1 0 0 16c1.1 0 1.8-.9 1.8-1.8 0-.5-.2-.9-.5-1.2-.3-.3-.5-.7-.5-1.2 0-.9.7-1.6 1.6-1.6h1.9A4.7 4.7 0 0 0 21 8.7C21 5.6 17 3 12 3Z" />
            <circle cx="7.5" cy="10.5" r="1" fill="currentColor" stroke="none" />
            <circle cx="11" cy="7" r="1" fill="currentColor" stroke="none" />
            <circle cx="15.5" cy="8" r="1" fill="currentColor" stroke="none" />
        </svg>
        @break

    @case('megaphone')
        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
            <path d="M3 10v4a1 1 0 0 0 1 1h2l7 4V5L6 9H4a1 1 0 0 0-1 1Z" />
            <path d="M17 8.5a4 4 0 0 1 0 7" />
        </svg>
        @break

    @case('card')
        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
            <rect x="3" y="6" width="18" height="12" rx="1.5" />
            <line x1="3" y1="10" x2="21" y2="10" />
        </svg>
        @break

    @case('book')
        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
            <path d="M4 5a2 2 0 0 1 2-2h5v18H6a2 2 0 0 1-2-2V5Z" />
            <path d="M11 3h7a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-7" />
        </svg>
        @break

    @case('route')
        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="6" cy="18" r="2.2" />
            <circle cx="18" cy="6" r="2.2" />
            <path d="M8 18h6a4 4 0 0 0 4-4V9" />
        </svg>
        @break

    @case('gift')
        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
            <rect x="3.5" y="9" width="17" height="4" rx="0.8" />
            <rect x="4.5" y="13" width="15" height="7" rx="0.8" />
            <line x1="12" y1="9" x2="12" y2="20" />
            <path d="M12 9C12 6 10 4.5 8.5 4.5 7 4.5 6 5.5 6 7c0 1.5 2 2 6 2Z" />
            <path d="M12 9c0-3 2-4.5 3.5-4.5C17 4.5 18 5.5 18 7c0 1.5-2 2-6 2Z" />
        </svg>
        @break

    @case('ticket')
        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
            <path d="M3 9a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v1.5a1.7 1.7 0 0 0 0 3V15a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-1.5a1.7 1.7 0 0 0 0-3V9Z" />
            <line x1="9" y1="7" x2="9" y2="17" stroke-dasharray="2 2" />
        </svg>
        @break

    @case('percent')
        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
            <line x1="19" y1="5" x2="5" y2="19" />
            <circle cx="7" cy="7" r="2.3" />
            <circle cx="17" cy="17" r="2.3" />
        </svg>
        @break

    @case('invite')
        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="9" cy="8" r="3" />
            <path d="M3.5 20a5.5 5.5 0 0 1 11 0" />
            <line x1="18" y1="7" x2="18" y2="13" />
            <line x1="15" y1="10" x2="21" y2="10" />
        </svg>
        @break

    @case('user')
        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="12" cy="8" r="3.5" />
            <path d="M4.5 20a7.5 7.5 0 0 1 15 0" />
        </svg>
        @break

    @case('chart')
        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
            <line x1="5" y1="20" x2="5" y2="12" />
            <line x1="12" y1="20" x2="12" y2="6" />
            <line x1="19" y1="20" x2="19" y2="15" />
        </svg>
        @break

    @case('close')
        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
            <line x1="6" y1="6" x2="18" y2="18" />
            <line x1="18" y1="6" x2="6" y2="18" />
        </svg>
        @break

    @case('menu')
        <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
            <line x1="3" y1="6" x2="21" y2="6" />
            <line x1="3" y1="12" x2="21" y2="12" />
            <line x1="3" y1="18" x2="21" y2="18" />
        </svg>
        @break

    @case('zap')
        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
            <polygon points="13 2 4 14 11 14 10 22 20 10 13 10 13 2" />
        </svg>
        @break

    @case('copy')
        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
            <rect x="9" y="9" width="12" height="12" rx="2" />
            <path d="M5 15H4a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1h10a1 1 0 0 1 1 1v1" />
        </svg>
        @break

    @case('qrcode')
        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
            <rect x="3" y="3" width="7" height="7" rx="1" />
            <rect x="14" y="3" width="7" height="7" rx="1" />
            <rect x="3" y="14" width="7" height="7" rx="1" />
            <line x1="14" y1="14" x2="14" y2="21" />
            <line x1="21" y1="14" x2="21" y2="21" />
            <line x1="17.5" y1="14" x2="17.5" y2="17.5" />
            <line x1="14" y1="17.5" x2="21" y2="17.5" />
        </svg>
        @break

    @case('copy')
        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
            <rect x="8" y="8" width="12" height="12" rx="1.5" />
            <path d="M5.5 16H5a1.5 1.5 0 0 1-1.5-1.5V5A1.5 1.5 0 0 1 5 3.5h9.5A1.5 1.5 0 0 1 16 5v.5" />
        </svg>
        @break

    @case('download')
        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
            <path d="M12 3v12" />
            <polyline points="7 10 12 15 17 10" />
            <path d="M4 17.5V19a1.5 1.5 0 0 0 1.5 1.5h13A1.5 1.5 0 0 0 20 19v-1.5" />
        </svg>
        @break

    @case('mail')
        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
            <rect x="3" y="5" width="18" height="14" rx="2" />
            <path d="M3.5 6.5 12 13l8.5-6.5" />
        </svg>
        @break

    @case('mail-lg')
        <svg xmlns="http://www.w3.org/2000/svg" class="w-10 h-10 shrink-0 mx-auto" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
            <rect x="3" y="5" width="18" height="14" rx="2" />
            <path d="M3.5 6.5 12 13l8.5-6.5" />
        </svg>
        @break

    @case('telegram')
        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
            <path d="M21 4 3 11l6 2.2M21 4 15 20l-6-6.8M21 4 9 13.2v5" />
        </svg>
        @break

    @case('inbox')
        <svg xmlns="http://www.w3.org/2000/svg" class="w-10 h-10 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
            <path d="M4 12h4l2 3h4l2-3h4" />
            <path d="M5.5 5.5h13l2 6.5v6a1.5 1.5 0 0 1-1.5 1.5h-14A1.5 1.5 0 0 1 3.5 18v-6l2-6.5Z" />
            <line x1="9.5" y1="9.5" x2="14.5" y2="14.5" />
            <line x1="14.5" y1="9.5" x2="9.5" y2="14.5" />
        </svg>
        @break
@endswitch
