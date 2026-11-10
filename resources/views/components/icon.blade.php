@props(['name', 'class' => 'size-5'])
<svg {{ $attributes->class($class) }} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.65" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
    @switch($name)
        @case("info") <circle cx="12" cy="12" r="9"/><path d="M12 11v5m0-8h.01"/> @break
        @case('dashboard') <rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/> @break
        @case('locations') <path d="M3 21h18M5 21V7h8v14m0-12h6v12M7 4h4M8 10h2m-2 4h2m-2 4h2m6-5h1m-1 4h1"/> @break
        @case('radar') <circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="5"/><path d="m12 12 7-7m-7 7h.01"/> @break
        @case('subnets') <rect x="9" y="2" width="6" height="5" rx="1"/><rect x="2" y="16" width="6" height="5" rx="1"/><rect x="16" y="16" width="6" height="5" rx="1"/><path d="M12 7v5m-7 4v-4h14v4"/> @break
        @case('addresses') <rect x="3" y="4" width="18" height="6" rx="2"/><rect x="3" y="14" width="18" height="6" rx="2"/><path d="M7 7h.01M7 17h.01M12 7h5m-5 10h5"/> @break
        @case('vlans') <path d="m12 3 9 5-9 5-9-5 9-5Zm-9 9 9 5 9-5m-18 4 9 5 9-5"/> @break
        @case('reserved') <path d="M6 4h12v17l-6-4-6 4V4Z"/> @break
        @case('accounts') <circle cx="9" cy="8" r="3"/><path d="M3 20v-2a5 5 0 0 1 5-5h2a5 5 0 0 1 5 5v2m2-15a3 3 0 0 1 0 6m4 9v-2a5 5 0 0 0-3-4.6"/> @break
        @case('preferences') <path d="M4 7h16M4 17h16"/><circle cx="8" cy="7" r="2.5" fill="var(--surface)"/><circle cx="16" cy="17" r="2.5" fill="var(--surface)"/> @break
        @case('sun') <circle cx="12" cy="12" r="4"/><path d="M12 2v2m0 16v2M2 12h2m16 0h2M5 5l1.5 1.5m11 11L19 19M5 19l1.5-1.5m11-11L19 5"/> @break
        @case('moon') <path d="M20.7 14A9 9 0 0 1 10 3.3 9 9 0 1 0 20.7 14Z"/> @break
        @case('plus') <path d="M12 5v14M5 12h14"/> @break
        @case('close') <path d="m6 6 12 12M6 18 18 6"/> @break
        @case('search') <circle cx="10.8" cy="10.8" r="5.8"/><path d="m15 15 4 4"/> @break
        @case('arrow') <path d="M4 12h15m-6-6 6 6-6 6"/> @break
        @case('logout') <path d="M10 5H5v14h5m0-7h10m-4-4 4 4-4 4"/> @break
        @case('edit') <path d="m14 6 4 4M4 20l5-1L20 8l-4-4L5 15l-1 5Z"/> @break
        @case('trash') <path d="M4 7h16M9 7V4h6v3m3 0-1 13H7L6 7m4 4v5m4-5v5"/> @break
        @case('menu') <path d="M4 6h16M4 12h16M4 18h16"/> @break
        @case('tools') <path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76Z"/> @break
        @case('audit') <path d="M9 4h6v3H9zM7 5.5H5.5A1.5 1.5 0 0 0 4 7v12.5A1.5 1.5 0 0 0 5.5 21h13a1.5 1.5 0 0 0 1.5-1.5V7a1.5 1.5 0 0 0-1.5-1.5H17M8 12h8m-8 4h5"/> @break
        @case('tag') <path d="M3 12V4a1 1 0 0 1 1-1h8l9 9-9 9-9-9Z"/><circle cx="7.5" cy="7.5" r="1.5"/> @break
        @case('history') <path d="M3 12a9 9 0 1 0 3-6.7L3 8m0-5v5h5m4-1v5l3 2"/> @break
        @case('bell') <path d="M6 9a6 6 0 1 1 12 0c0 6 2.5 7.5 2.5 7.5h-17S6 15 6 9Zm4 11a2 2 0 0 0 4 0"/> @break
        @case('switch') <rect x="2" y="7" width="20" height="10" rx="2"/><path d="M6 11h1m3 0h1m3 0h1m3 0h1M6 14h12"/> @break
        @case('outlet') <rect x="4" y="4" width="16" height="16" rx="3"/><path d="M9 10v2m6-2v2m-5 4h4"/> @break
        @case('patch') <rect x="2" y="6" width="20" height="12" rx="2"/><path d="M6 10h2m3 0h2m3 0h2M6 14h2m3 0h2m3 0h2"/> @break
        @case('print') <path d="M6 9V3h12v6M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8" rx="1"/> @break
        @case('rack') <rect x="5" y="2" width="14" height="20" rx="1.5"/><path d="M8 6h8M8 10h8M8 14h8M8 18h3"/> @break
        @case('topology') <rect x="9" y="2" width="6" height="5" rx="1"/><rect x="2" y="17" width="6" height="5" rx="1"/><rect x="16" y="17" width="6" height="5" rx="1"/><path d="M12 7v3M12 10 5 17m7-7 7 7M8 19.5h8"/> @break
        @case('globe') <circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c2.5 2.6 3.8 5.6 3.8 9s-1.3 6.4-3.8 9c-2.5-2.6-3.8-5.6-3.8-9S9.5 5.6 12 3Z"/> @break
        @case('chevron') <path d="m6 9 6 6 6-6"/> @break
        @case('shield') <path d="M12 3 4 6v6c0 4.5 3.4 8.3 8 9 4.6-.7 8-4.5 8-9V6l-8-3Z"/><path d="m9 12 2 2 4-4"/> @break
        @case('building') <path d="M4 21V5a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v16M16 9h2a2 2 0 0 1 2 2v10M3 21h18M8 7h4M8 11h4M8 15h4"/> @break
        @case('cloud') <path d="M7 18a4 4 0 0 1-.6-7.96A5.5 5.5 0 0 1 17 9a3.5 3.5 0 0 1 .5 6.97V18H7Z"/> @break
        @case('microsoft') <rect x="3" y="3" width="8.5" height="8.5" fill="#f25022" stroke="none"/><rect x="12.5" y="3" width="8.5" height="8.5" fill="#7fba00" stroke="none"/><rect x="3" y="12.5" width="8.5" height="8.5" fill="#00a4ef" stroke="none"/><rect x="12.5" y="12.5" width="8.5" height="8.5" fill="#ffb900" stroke="none"/> @break
        @case('link') <path d="M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1"/> @break
        @case('info') <circle cx="12" cy="12" r="9"/><path d="M12 11v5m0-8h.01"/> @break
        @case('check') <path d="m5 12.5 4.5 4.5L19 7.5"/> @break
        @case('copy') <rect x="8" y="8" width="12" height="12" rx="2"/><path d="M16 8V5.5A1.5 1.5 0 0 0 14.5 4h-9A1.5 1.5 0 0 0 4 5.5v9A1.5 1.5 0 0 0 5.5 16H8"/> @break
        @case('key') <circle cx="8" cy="9" r="4"/><path d="m11 12 8 8m-3-3 3-3m-5 1 2-2"/> @break
    @endswitch
</svg>
