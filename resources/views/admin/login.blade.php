<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>Admin sign in · Nearby Teacher</title>
    <style>
        :root{font-family:Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:#17243a;background:#f3f6fb;font-synthesis:none;--blue:#2864dc;--muted:#748196;--line:#e1e7f0;--danger:#b12836}
        *{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;padding:24px;background:radial-gradient(ellipse at 12% 8%,#e4edff 0,transparent 38%),radial-gradient(ellipse at 95% 90%,#e8f5f0 0,transparent 35%),#f3f6fb}
        main{width:min(440px,100%);padding:34px;background:rgba(255,255,255,.96);border:1px solid rgba(218,226,239,.9);border-radius:18px;box-shadow:0 22px 70px rgba(30,54,89,.1)}
        .brand{display:flex;align-items:center;gap:12px;margin-bottom:30px}.brand-mark{width:42px;height:42px;background:#2864dc;border-radius:13px;color:#fff;display:grid;place-items:center;font-weight:800;font-size:17px}.brand small{display:block;color:#7e8ba0;font-size:11px;margin-top:3px}.brand strong{font-size:14px}
        .eyebrow{font-size:10px;text-transform:uppercase;letter-spacing:.13em;color:var(--blue);font-weight:800;margin:0 0 9px}h1{font-size:27px;letter-spacing:-.04em;margin:0;color:#19263c} .intro{font-size:13px;line-height:1.6;color:var(--muted);margin:9px 0 22px}
        .notice{padding:12px 13px;border:1px solid #f0d99b;background:#fff9e9;border-radius:9px;color:#775d1b;font-size:12px;line-height:1.55;margin:0 0 17px}.notice code{font-size:11px}
        .error-summary{padding:12px 13px;border:1px solid #f0c5ca;background:#fff3f4;border-radius:9px;color:#9f2633;font-size:12px;margin:0 0 17px}.error-summary strong{display:block;margin-bottom:5px}.error-summary ul{padding-left:17px;margin:0}.error-summary li+li{margin-top:3px}
        .field{margin:0 0 17px}.field label{display:block;color:#354258;font-size:12px;font-weight:700;margin-bottom:7px}.field input{width:100%;min-height:45px;border:1px solid #d8e0eb;border-radius:8px;padding:11px 12px;background:#fff;color:#1a2638;font:inherit;font-size:13px;outline:none;transition:border .15s,box-shadow .15s}.field input:focus{border-color:#6591ed;box-shadow:0 0 0 3px rgba(53,105,217,.12)}.field input::placeholder{color:#9aa5b4}.field input.invalid{border-color:#dd5965;background:#fffafb}.field input.invalid:focus{box-shadow:0 0 0 3px rgba(196,56,69,.1)}.field-error{font-size:12px;color:var(--danger);margin:6px 0 0;line-height:1.45}.field-error:before{content:"!";display:inline-grid;place-items:center;width:15px;height:15px;margin-right:5px;border-radius:50%;background:#fde7e9;font-size:10px;font-weight:800}
        button{width:100%;min-height:45px;margin-top:6px;padding:11px;background:var(--blue);color:#fff;border:1px solid var(--blue);border-radius:8px;font:inherit;font-size:13px;font-weight:700;cursor:pointer;transition:background .15s}button:hover:not(:disabled){background:#1f50b5}button:disabled{background:#a8b9d7;border-color:#a8b9d7;cursor:not-allowed}
        .footnote{font-size:11px;color:#8995a7;text-align:center;margin:20px 0 0}
        @media(max-width:480px){main{padding:26px 22px;border-radius:14px}}
    </style>
</head>
<body>
<main>
    <div class="brand"><span class="brand-mark" aria-hidden="true">N</span><span><strong>Nearby Teacher</strong><small>Admin workspace</small></span></div>
    <p class="eyebrow">Secure sign in</p>
    <h1>Welcome back</h1>
    <p class="intro">Sign in to manage subjects, students, teachers, and institutes.</p>

    @if (! $configured)
        <p class="notice" role="status">Admin access is disabled until <code>ADMIN_EMAIL</code> and <code>ADMIN_PASSWORD_HASH</code> are configured.</p>
    @endif
    @if ($errors->any())
        <div class="error-summary" role="alert"><strong>We couldn't sign you in</strong><ul>@foreach ($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul></div>
    @endif
    <form method="post" action="{{ route('admin.authenticate') }}">
        @csrf
        <div class="field">
            <label for="email">Admin email</label>
            <input id="email" name="email" type="email" autocomplete="username" placeholder="you@example.com" value="{{ old('email') }}" required @class(['invalid' => $errors->has('email')]) @if ($errors->has('email')) aria-invalid="true" aria-describedby="email-error" @endif>
            @error('email')<p class="field-error" id="email-error">{{ $message }}</p>@enderror
        </div>
        <div class="field">
            <label for="password">Password</label>
            <input id="password" name="password" type="password" autocomplete="current-password" placeholder="Enter your password" required @class(['invalid' => $errors->has('password')]) @if ($errors->has('password')) aria-invalid="true" aria-describedby="password-error" @endif>
            @error('password')<p class="field-error" id="password-error">{{ $message }}</p>@enderror
        </div>
        <button type="submit" @disabled(! $configured)>Sign in to admin</button>
    </form>
    <p class="footnote">Protected administrator access · <a href="{{ route('developer.login') }}">Developer portal</a></p>
</main>
<script>
    document.querySelectorAll('input.invalid').forEach((control) => {
        control.addEventListener('input', () => {
            control.classList.remove('invalid');
            control.removeAttribute('aria-invalid');
            const errorId = control.getAttribute('aria-describedby');
            if (errorId) document.getElementById(errorId)?.remove();
            control.removeAttribute('aria-describedby');
        }, { once: true });
    });
</script>
</body>
</html>
