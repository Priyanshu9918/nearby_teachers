<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>Get the Nearby Teacher app</title>
    <style>
        body{margin:0;min-height:100vh;display:grid;place-items:center;background:#fcfcfc;color:#111827;font-family:system-ui,-apple-system,sans-serif}
        main{width:min(400px,calc(100% - 40px));text-align:center}
        h1{font-size:24px}p{color:#667085}
        .links{display:grid;gap:12px;margin-top:24px}
        a{display:block;padding:14px;border-radius:8px;background:#102b4e;color:white;text-decoration:none}
    </style>
</head>
<body>
<main>
    <h1>Nearby Teacher</h1>
    <p>Download the app to open this link.</p>
    <div class="links">
        @if ($androidUrl)
            <a href="{{ $androidUrl }}">Download for Android</a>
        @endif
        @if ($iosUrl)
            <a href="{{ $iosUrl }}">Download for iPhone</a>
        @endif
    </div>
</main>
</body>
</html>
