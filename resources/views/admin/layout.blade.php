<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>@yield('title', 'Admin') · Nearby Teacher</title>
    <style>
        :root{font-family:Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:#17212f;background:#f4f7fb;font-synthesis:none;text-rendering:optimizeLegibility;--navy:#14243b;--blue:#2864dc;--blue-dark:#1f50b5;--muted:#748196;--line:#e5eaf1;--danger:#c43845;--green:#18865b}
        *{box-sizing:border-box}body{margin:0;min-height:100vh}a{color:inherit}
        .shell{min-height:100vh}.sidebar{position:fixed;inset:0 auto 0 0;width:252px;background:var(--navy);color:#e7edf7;padding:24px 16px;display:flex;flex-direction:column;z-index:5}
        .brand{display:flex;align-items:center;gap:12px;padding:4px 10px 28px}.brand-mark{width:38px;height:38px;border-radius:12px;background:#3c75ed;display:grid;place-items:center;font-weight:800;color:white}.brand strong{display:block;font-size:15px;letter-spacing:.01em}.brand small{display:block;color:#9eacc2;margin-top:3px;font-size:12px}
        .side-label{color:#8998ae;text-transform:uppercase;letter-spacing:.11em;font-weight:700;font-size:10px;padding:4px 12px 10px}.side-nav{display:grid;gap:6px}.side-nav a{display:flex;align-items:center;gap:12px;padding:11px 12px;border-radius:9px;color:#b9c6d8;text-decoration:none;font-size:14px;font-weight:550;transition:background .15s,color .15s}.side-nav a:hover{background:#203550;color:#fff}.side-nav a.active{background:#2c4770;color:#fff}.nav-icon{width:20px;text-align:center;font-size:16px}.sidebar-bottom{margin-top:auto;border-top:1px solid #2a3b52;padding:16px 10px 0;color:#97a5b9;font-size:12px;line-height:1.6}
        .workspace{margin-left:252px;min-height:100vh}.topbar{height:72px;background:#fff;border-bottom:1px solid var(--line);display:flex;align-items:center;justify-content:space-between;padding:0 clamp(20px,4vw,54px);position:sticky;top:0;z-index:3}.topbar-title{font-size:14px;color:var(--muted)}.topbar-title strong{color:#1c293a;font-weight:700}.top-actions{display:flex;align-items:center;gap:14px}.admin-chip{display:flex;align-items:center;gap:9px;font-size:13px;color:#4d5a6e}.avatar{width:34px;height:34px;border-radius:50%;background:#e9effd;color:#2859bd;display:grid;place-items:center;font-weight:750}.logout{border:0;background:transparent;color:#59677c;font:inherit;font-weight:600;cursor:pointer;padding:8px 10px;border-radius:8px}.logout:hover{background:#f2f5fa;color:#15243a}.mobile-menu{display:none}
        main{width:min(1440px,100%);padding:34px clamp(20px,4vw,54px) 56px;margin:auto}.page-heading{display:flex;align-items:flex-end;justify-content:space-between;gap:18px;margin-bottom:24px}.eyebrow{text-transform:uppercase;color:var(--blue);font-size:11px;font-weight:750;letter-spacing:.12em;margin:0 0 8px}.page-heading h1{font-size:clamp(25px,3vw,32px);letter-spacing:-.035em;margin:0;color:#162338}.page-heading p{margin:7px 0 0;color:var(--muted);font-size:14px}.count-pill{white-space:nowrap;border:1px solid var(--line);background:#fff;color:#526077;padding:9px 13px;border-radius:999px;font-size:12px;font-weight:650}
        .flash{display:flex;gap:10px;align-items:flex-start;margin:0 0 18px;padding:13px 15px;border:1px solid #bde7d3;border-radius:10px;background:#effaf4;color:#176344;font-size:13px}.flash-error{border-color:#f0c5ca;background:#fff3f4;color:#9f2633}.flash strong{display:block;margin-bottom:3px}.flash ul{padding-left:18px;margin:6px 0 0}.flash li+li{margin-top:3px}
        .panel{background:#fff;border:1px solid var(--line);border-radius:15px;box-shadow:0 5px 22px rgba(25,45,75,.035);margin-bottom:22px;overflow:hidden}.panel-head{display:flex;justify-content:space-between;align-items:center;gap:14px;padding:20px 24px;border-bottom:1px solid #edf0f5}.panel-head h2{margin:0;font-size:16px;color:#202e43}.panel-head p{margin:5px 0 0;color:var(--muted);font-size:12px}.panel-body{padding:22px 24px}
        .form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:0 18px}.field{min-width:0;margin-bottom:17px}.field label{display:flex;gap:5px;align-items:center;color:#344258;font-size:12px;font-weight:700;margin-bottom:7px}.required{color:var(--danger)}.field input,.field select,.field textarea{width:100%;min-height:43px;border:1px solid #d8e0eb;border-radius:8px;background:#fff;padding:10px 12px;color:#1a2638;font:inherit;font-size:13px;outline:none;transition:border .15s,box-shadow .15s}.field textarea{min-height:88px;resize:vertical}.field input:focus,.field select:focus,.field textarea:focus{border-color:#6591ed;box-shadow:0 0 0 3px rgba(53,105,217,.12)}.field input::placeholder,.field textarea::placeholder{color:#9aa5b4}.field .invalid{border-color:#dd5965;background:#fffafb}.field .invalid:focus{box-shadow:0 0 0 3px rgba(196,56,69,.1)}.help{font-size:11px;color:#8591a3;margin:6px 0 0;line-height:1.5}.field-error{font-size:12px;color:#b12836;margin:6px 0 0;line-height:1.45}.field-error:before{content:"!";display:inline-grid;place-items:center;width:15px;height:15px;margin-right:5px;border-radius:50%;background:#fde7e9;font-size:10px;font-weight:800}.check-field{display:flex;align-items:center;gap:9px;margin:3px 0 20px;color:#35435a;font-size:13px;font-weight:600}.check-field input{width:16px;height:16px;accent-color:var(--blue)}.form-actions{display:flex;align-items:center;gap:10px;margin-top:4px;padding-top:17px;border-top:1px solid #edf0f5}.btn{display:inline-flex;align-items:center;justify-content:center;gap:7px;min-height:39px;border:1px solid #dce3ed;border-radius:8px;padding:8px 13px;background:white;color:#344258;font:inherit;font-size:12px;font-weight:700;text-decoration:none;cursor:pointer;transition:background .15s,border .15s}.btn:hover{background:#f5f7fb}.btn-primary{background:var(--blue);border-color:var(--blue);color:white}.btn-primary:hover{background:var(--blue-dark);border-color:var(--blue-dark)}.btn-danger{color:#b32f3c;border-color:#f0c9cd}.btn-danger:hover{background:#fff2f3}.btn-small{min-height:32px;padding:6px 10px}
        .table-wrap{overflow:auto}.data-table{width:100%;border-collapse:collapse;white-space:nowrap}.data-table th{text-align:left;background:#f9fafc;color:#8290a2;font-size:10px;text-transform:uppercase;letter-spacing:.09em;font-weight:750;padding:12px 18px;border-bottom:1px solid var(--line)}.data-table td{padding:14px 18px;border-bottom:1px solid #eef1f5;color:#354258;font-size:12px;vertical-align:middle}.data-table tr:last-child td{border-bottom:0}.data-table tbody tr:hover{background:#fbfcfe}.record-name{color:#1d2a3e;font-weight:700}.record-sub{display:block;color:#8b96a6;font-size:11px;margin-top:4px}.badge{display:inline-flex;align-items:center;border-radius:999px;background:#eaf8f1;color:#16804f;padding:4px 9px;font-size:10px;font-weight:750}.table-actions{display:flex;align-items:center;gap:7px}.table-actions form{margin:0}.empty-state{text-align:center;padding:42px 18px;color:#7e8a9c}.empty-icon{width:42px;height:42px;margin:0 auto 12px;border-radius:13px;background:#f0f4fb;color:#6082bd;display:grid;place-items:center;font-size:18px}.empty-state strong{display:block;color:#344258;font-size:13px}.empty-state p{font-size:12px;margin:5px 0 0}
        @media(max-width:850px){.sidebar{width:218px}.workspace{margin-left:218px}.topbar{padding:0 22px}main{padding:28px 22px 42px}}
        @media(max-width:650px){.sidebar{position:static;width:auto;height:auto;padding:10px 12px}.brand{padding:4px 6px 10px}.brand-mark{width:32px;height:32px}.sidebar .side-label,.sidebar-bottom{display:none}.side-nav{display:flex;overflow:auto;padding-bottom:2px}.side-nav a{flex:none;padding:9px 10px;font-size:12px}.nav-icon{display:none}.workspace{margin:0}.topbar{height:60px;padding:0 16px}.topbar-title{font-size:12px}.admin-chip span{display:none}.top-actions{gap:5px}.logout{padding:7px;font-size:12px}main{padding:24px 15px 36px}.page-heading{align-items:flex-start}.page-heading h1{font-size:26px}.count-pill{font-size:11px;padding:7px 10px}.panel-head{padding:17px}.panel-body{padding:17px}.form-grid{grid-template-columns:1fr;gap:0}.field{margin-bottom:14px}.data-table th,.data-table td{padding:12px}.form-actions{flex-wrap:wrap}}
    </style>
</head>
<body>
<div class="shell">
    <aside class="sidebar" aria-label="Admin sidebar">
        <a class="brand" href="{{ route('admin.home') }}" style="text-decoration:none">
            <span class="brand-mark">N</span>
            <span><strong>Nearby Teacher</strong><small>Administration</small></span>
        </a>
        <div class="side-label">Workspace</div>
        <nav class="side-nav" aria-label="Admin sections" style="overflow:auto">
            <a href="{{ route('admin.dashboard') }}" @class(['active' => request()->routeIs('admin.dashboard')])><span class="nav-icon">▤</span>Project dashboard</a>
            <a href="{{ route('admin.reports.index', ['report' => 'users']) }}" @class(['active' => request()->routeIs('admin.reports.*')])><span class="nav-icon">▥</span>Project reports</a>
            @foreach (['subjects' => ['Subjects', '◈'], 'students' => ['Students', '♙'], 'teachers' => ['Teachers', '✦'], 'institutes' => ['Institutes', '▦']] as $key => [$label, $icon])
                <a href="{{ route('admin.index', ['section' => $key]) }}" @class(['active' => ($section ?? '') === $key]) @if (($section ?? '') === $key) aria-current="page" @endif>
                    <span class="nav-icon" aria-hidden="true">{{ $icon }}</span>{{ $label }}
                </a>
            @endforeach
        </nav>
        <div class="sidebar-bottom">Nearby Teacher<br>Admin workspace</div>
    </aside>
    <div class="workspace">
        <header class="topbar">
            <div class="topbar-title"><strong>Admin portal</strong><span> / @yield('breadcrumb', 'Workspace')</span></div>
            <div class="top-actions">
                <div class="admin-chip"><span>Administrator</span><span class="avatar" aria-hidden="true">A</span></div>
                <form method="post" action="{{ route('admin.logout') }}">@csrf<button class="logout" type="submit">Sign out</button></form>
            </div>
        </header>
        <main>
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
