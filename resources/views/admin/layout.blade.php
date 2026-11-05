<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <title>@yield('title', 'Admin') · Nearby Teacher</title>
    <script src="{{ asset('js/admin-theme.js') }}?v={{ substr(hash_file('sha256', public_path('js/admin-theme.js')), 0, 12) }}"></script>
    <link rel="stylesheet" href="{{ asset('css/admin.css') }}?v={{ substr(hash_file('sha256', public_path('css/admin.css')), 0, 12) }}">
    <script src="{{ asset('js/admin.js') }}?v={{ substr(hash_file('sha256', public_path('js/admin.js')), 0, 12) }}" defer></script>
</head>
<body>
<a class="skip-link" href="#main-content">Skip to content</a>
<div class="shell">
    @include('admin.partials.sidebar')
    <div class="workspace">
        <header class="admin-topbar">
            <div class="admin-topbar-heading">
                <button class="mobile-nav-trigger" id="open-admin-nav" type="button" aria-label="Open admin sidebar" aria-controls="admin-sidebar" aria-expanded="false"><x-admin-icon name="menu" /><span>Menu</span></button>
                <div class="admin-page-title"><span>BSL ADMIN WORKSPACE</span><strong>@yield('title', 'Admin')</strong></div>
            </div>
            <div class="admin-topbar-actions">
                <button class="theme-toggle" id="admin-theme-toggle" type="button" aria-pressed="false">
                    <x-admin-icon class="theme-icon-sun" name="sun" />
                    <x-admin-icon class="theme-icon-moon" name="moon" />
                    <span data-theme-label>Dark mode</span>
                </button>
                <span class="topbar-admin-avatar" aria-hidden="true">{{ strtoupper(substr((string) config('admin.email', 'A'), 0, 1)) }}</span>
                <form class="topbar-logout-form" method="post" action="{{ route('admin.logout') }}">
                    @csrf
                    <button class="topbar-logout" type="submit"><x-admin-icon name="logout" /><span>Sign out</span></button>
                </form>
            </div>
        </header>
        <main id="main-content" tabindex="-1">
            @if (session('status'))
                <div class="flash" role="status"><span aria-hidden="true">✓</span><div><strong>Done</strong>{{ session('status') }}</div></div>
            @endif
            @if ($errors->any())
                <div class="flash flash-error" role="alert">
                    <span aria-hidden="true">!</span>
                    <div><strong>Please check the form</strong>
                        <ul>@foreach ($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul>
                    </div>
                </div>
            @endif
            @yield('content')
        </main>
    </div>
</div>
<script>
    document.querySelectorAll('.field input, .field select, .field textarea').forEach((control) => {
        if (control.classList.contains('invalid')) {
            const clearFieldError = () => {
                control.classList.remove('invalid');
                control.removeAttribute('aria-invalid');
                const errorId = control.getAttribute('aria-describedby');
                if (errorId) document.getElementById(errorId)?.remove();
                control.removeAttribute('aria-describedby');
            };
            control.addEventListener('input', clearFieldError, { once: true });
            control.addEventListener('change', clearFieldError, { once: true });
        }
    });
</script>
</body>
</html>
