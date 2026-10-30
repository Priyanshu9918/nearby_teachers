<!doctype html>
<html lang="@yield('documentLanguage', $lang)" dir="@yield('documentDirection', data_get($t, '_meta.rtl') ? 'rtl' : 'ltr')">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') — {{ data_get($t, 'common.appName', 'Nearby Teacher') }}</title>
    <meta name="description" content="@yield('description', data_get($t, 'website.findNearYou'))">
    @yield('meta')
    <style>
        :root{--app-primary:#102B4E;--app-primary-soft:#EAF0F6;--app-background:#FCFCFC;--app-surface:#fff;--app-text-primary:#111827;--app-text-secondary:#667085;--app-border:#E6E8EC;--app-map-background:#EEF4FB;--app-map-radius:#2F80FF;--app-success:#20A464;--app-danger:#D92D20}
        *{box-sizing:border-box}body{font-family:-apple-system,Segoe UI,Roboto,"Noto Sans Arabic","Noto Sans SC",sans-serif;background:var(--app-background);color:var(--app-text-primary);margin:0}
        .wrap{max-width:760px;margin:0 auto;padding:32px 20px 60px}h1{font-size:24px;font-weight:600;margin:0 0 6px}h2{font-size:17px;margin:28px 0 12px}
        .sub,.meta{color:var(--app-text-secondary);font-size:14px}.sub{margin:0 0 24px}.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:10px}
        .list{display:flex;flex-direction:column;gap:10px}.card,.row,.city-card,.post{background:var(--app-surface);border:1px solid var(--app-border);border-radius:12px;padding:16px}
        .row,.city-card{display:flex;align-items:center;justify-content:space-between;text-decoration:none;color:inherit}.row:hover,.city-card:hover{border-color:var(--app-map-radius)}
        .name{font-size:14px;font-weight:600;margin:0}.meta{font-size:12px;margin:3px 0 0}.count{color:var(--app-text-secondary);font-size:12px}
        .tags{display:flex;flex-wrap:wrap;gap:8px;margin:16px 0}.tag{font-size:13px;padding:5px 12px;border-radius:12px;background:var(--app-primary-soft);color:var(--app-primary)}
        .badge{display:inline-block;font-size:12px;padding:3px 10px;border-radius:10px;background:var(--app-primary-soft);color:var(--app-primary);margin:8px 6px 0 0}
        .badge.trial{background:var(--app-primary-soft);color:var(--app-primary)}.badge.free{font-weight:600}.badge.bsl{background:var(--app-primary-soft);color:var(--app-primary);font-weight:600}
        .profile-card{border-radius:16px;padding:28px 24px}.avatar{width:64px;height:64px;border-radius:50%;background:var(--app-primary-soft);color:var(--app-primary);display:flex;align-items:center;justify-content:center;font-size:22px;font-weight:600;overflow:hidden}
        .institute .avatar{border-radius:16px;background:var(--app-primary-soft);color:var(--app-primary)}.avatar img{width:100%;height:100%;object-fit:cover}
        .bio{font-size:15px;line-height:1.6;margin:20px 0}.btn{display:inline-block;padding:11px 20px;border-radius:8px;background:var(--app-primary);color:#fff;text-decoration:none;font-size:14px;font-weight:500}
        .footer-note{text-align:center;font-size:13px;color:var(--app-text-secondary);margin-top:24px}.footer-note a,.text-link{color:var(--app-primary)}
        .empty{text-align:center;color:var(--app-text-secondary);padding:32px 0}.post{margin-bottom:12px}.post-top{display:flex;align-items:center;gap:9px;margin-bottom:6px}.post .avatar{width:32px;height:32px;font-size:12px}
        .type-tag{font-size:11px;padding:2px 8px;border-radius:8px;background:var(--app-primary-soft);color:var(--app-primary);display:inline-block;margin-bottom:6px}.stats{display:flex;gap:16px;font-size:12px;color:var(--app-text-secondary)}
        .center{min-height:100vh;display:flex;align-items:center;justify-content:center}.box{width:min(420px,calc(100% - 40px));text-align:center;padding:24px}.links{display:flex;flex-direction:column;gap:10px}.btn.dark{background:var(--app-primary)}.btn.disabled{background:var(--app-border);color:var(--app-text-secondary);pointer-events:none}
        .lang-picker{margin-top:28px;padding-top:16px;border-top:1px solid var(--app-border)}.lang-picker select{font-size:13px;padding:6px 10px;border-radius:6px;border:1px solid var(--app-border);background:var(--app-surface);color:var(--app-text-primary)}
    </style>
</head>
<body>
    @yield('content')
</body>
</html>
