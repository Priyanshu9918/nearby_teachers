@props(['name' => 'document'])
@php
    $paths = [
        'dashboard' => ['M3 3h7v7H3zM14 3h7v7h-7zM3 14h7v7H3zM14 14h7v7h-7z'],
        'users' => ['M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M16 3a4 4 0 0 1 0 8M22 21v-2a4 4 0 0 0-3-3.87', 'M13 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0Z'],
        'user' => ['M20 21v-2a7 7 0 0 0-14 0v2M17 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0Z'],
        'building' => ['M4 21V5l8-3 8 3v16M2 21h20M9 21v-5h6v5M8 7h1M15 7h1M8 11h1M15 11h1'],
        'chat' => ['M21 11.5a8.4 8.4 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.4 8.4 0 0 1-3.8-.9L3 21l1.9-5.7a8.4 8.4 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.4 8.4 0 0 1 3.8-.9h.5a8.5 8.5 0 0 1 8 8v.5Z', 'M8 11h8M8 15h4'],
        'calendar' => ['M8 2v4M16 2v4M3 10h18M5 4h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2Z'],
        'shield' => ['M12 22s9-4 9-11V5l-9-3-9 3v6c0 7 9 11 9 11Z', 'm9 12 2 2 4-4'],
        'book' => ['M12 7v14M3 3c4-1 7 0 9 3 2-3 5-4 9-3v15c-4-1-7 0-9 3-2-3-5-4-9-3Z'],
        'chart' => ['M3 3v18h18M7 15l4-5 4 3 5-7'],
        'lock' => ['M5 11h14v10H5zM8 11V7a4 4 0 0 1 8 0v4M12 15v2'],
        'document' => ['M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z', 'M14 2v6h6M8 13h8M8 17h5'],
        'search' => ['M21 21l-5-5M18 10a8 8 0 1 1-16 0 8 8 0 0 1 16 0Z'],
        'arrow' => ['M5 12h14m-5-5 5 5-5 5'],
        'chevron' => ['m9 5 7 7-7 7'],
        'menu' => ['M3 6h18M3 12h18M3 18h18'],
        'close' => ['m6 6 12 12M6 18 18 6'],
        'logout' => ['M9 21H4V3h5M9 12h12m-5-5 5 5-5 5'],
        'check' => ['m5 12 4 4L19 6'],
        'plus' => ['M12 5v14M5 12h14'],
        'download' => ['M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3'],
        'photo' => ['M4 5h16a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2Z', 'M8.5 10a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3Z', 'm21 15-5-5L5 19'],
        'flag' => ['M5 21V4m0 1c5-4 9 4 14 0v11c-5 4-9-4-14 0'],
        'briefcase' => ['M3 7h18v14H3z', 'M8 7V4h8v3M3 12h18m-11 0v2h4v-2'],
    ];
@endphp
<svg {{ $attributes->class(['icon']) }} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">@foreach ($paths[$name] ?? $paths['document'] as $path)<path d="{{ $path }}"/>@endforeach</svg>
