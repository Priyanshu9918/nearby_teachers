<!doctype html>
<html lang="{{ $lang }}" dir="{{ data_get($t, '_meta.rtl') ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') — {{ data_get($t, 'common.appName', 'Nearby Teacher') }}</title>
    <meta name="description" content="@yield('description', data_get($t, 'website.findNearYou'))">
    @yield('meta')
    <style>
        *{box-sizing:border-box}body{font-family:-apple-system,Segoe UI,Roboto,"Noto Sans Arabic","Noto Sans SC",sans-serif;background:#f7f6f2;color:#2c2c2a;margin:0}
        .wrap{max-width:760px;margin:0 auto;padding:32px 20px 60px}h1{font-size:24px;font-weight:600;margin:0 0 6px}h2{font-size:17px;margin:28px 0 12px}
        .sub,.meta{color:#73726c;font-size:14px}.sub{margin:0 0 24px}.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:10px}
        .list{display:flex;flex-direction:column;gap:10px}.card,.row,.city-card,.post{background:#fff;border:1px solid #e2e0d8;border-radius:12px;padding:16px}
        .row,.city-card{display:flex;align-items:center;justify-content:space-between;text-decoration:none;color:inherit}.row:hover,.city-card:hover{border-color:#b4b2a9}
        .name{font-size:14px;font-weight:600;margin:0}.meta{font-size:12px;margin:3px 0 0}.count{color:#73726c;font-size:12px}
        .tags{display:flex;flex-wrap:wrap;gap:8px;margin:16px 0}.tag{font-size:13px;padding:5px 12px;border-radius:12px;background:#f1efe8}
        .badge{display:inline-block;font-size:12px;padding:3px 10px;border-radius:10px;background:#eaf3de;color:#3b6d11;margin:8px 6px 0 0}
        .badge.trial{background:#e6f1fb;color:#185fa5}.badge.free{font-weight:600}.badge.bsl{background:#faece7;color:#993c1d;font-weight:600}
        .profile-card{border-radius:16px;padding:28px 24px}.avatar{width:64px;height:64px;border-radius:50%;background:#e6f1fb;color:#0c447c;display:flex;align-items:center;justify-content:center;font-size:22px;font-weight:600;overflow:hidden}
        .institute .avatar{border-radius:16px;background:#e1f5ee;color:#085041}.avatar img{width:100%;height:100%;object-fit:cover}
        .bio{font-size:15px;line-height:1.6;margin:20px 0}.btn{display:inline-block;padding:11px 20px;border-radius:8px;background:#185fa5;color:#fff;text-decoration:none;font-size:14px;font-weight:500}
        .footer-note{text-align:center;font-size:13px;color:#888780;margin-top:24px}.footer-note a,.text-link{color:#185fa5}
        .empty{text-align:center;color:#73726c;padding:32px 0}.post{margin-bottom:12px}.post-top{display:flex;align-items:center;gap:9px;margin-bottom:6px}.post .avatar{width:32px;height:32px;font-size:12px}
        .type-tag{font-size:11px;padding:2px 8px;border-radius:8px;background:#e6f1fb;color:#0c447c;display:inline-block;margin-bottom:6px}.stats{display:flex;gap:16px;font-size:12px;color:#73726c}
        .center{min-height:100vh;display:flex;align-items:center;justify-content:center}.box{width:min(420px,calc(100% - 40px));text-align:center;padding:24px}.links{display:flex;flex-direction:column;gap:10px}.btn.dark{background:#2c2c2a}.btn.disabled{background:#e2e0d8;color:#888780;pointer-events:none}
        .lang-picker{margin-top:28px;padding-top:16px;border-top:1px solid #e2e0d8}.lang-picker select{font-size:13px;padding:6px 10px;border-radius:6px;border:1px solid #d3d1c7;background:#fff}
    </style>
</head>
<body>
    @yield('content')
</body>
</html>
