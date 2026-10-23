<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>@yield('title', 'Admin') · Nearby Teacher</title>
    <link rel="stylesheet" href="{{ asset('css/admin.css') }}?v={{ substr(hash_file('sha256', public_path('css/admin.css')), 0, 12) }}">
    <script src="{{ asset('js/admin.js') }}" defer></script>
</head>
<body>
<a class="skip-link" href="#main-content">Skip to content</a>
<div class="shell">
    @include('admin.partials.sidebar')
    <div class="workspace">
        <header class="topbar">
            <div class="topbar-left"><button class="icon-button mobile-only" id="open-admin-nav" type="button" aria-label="Open navigation" aria-controls="admin-sidebar" aria-expanded="false"><x-admin-icon name="menu" /></button><div class="topbar-title"><span>Workspace</span><x-admin-icon name="chevron" /><strong>@yield('breadcrumb', 'Overview')</strong></div></div>
            <div class="top-actions">
                <a class="topbar-reports" href="{{ route('admin.dashboard') }}#report-library"><x-admin-icon name="document" /> All reports</a><div class="admin-chip"><span class="avatar" aria-hidden="true">A</span><span>Administrator</span></div>
                <form method="post" action="{{ route('admin.logout') }}">@csrf<button class="logout" type="submit"><x-admin-icon name="logout" /><span>Sign out</span></button></form>
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
