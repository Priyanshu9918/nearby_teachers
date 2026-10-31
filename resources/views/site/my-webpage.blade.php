@extends('site.layout')
@section('documentLanguage', 'en')
@php
    $subjectNames = $subjects->pluck('name')->implode(' · ');
    $place = collect([$provider->city, $provider->state])->filter()->unique()->implode(', ');
    $shareText = 'Find '.$provider->full_name.' on Nearby Teacher';
@endphp
@section('title', 'Your web page — '.$provider->full_name)
@section('description', 'Share your Nearby Teacher page and print a QR poster.')
@section('content')
<style>
    body{background:var(--app-background);color:var(--app-text-primary)}
    .webpage{max-width:1180px;margin:0 auto;padding:34px 24px 60px;display:grid;grid-template-columns:minmax(320px,430px) minmax(360px,1fr);gap:34px;align-items:center}
    .webpage h1{font-size:28px;margin:0 0 20px;color:var(--app-text-primary)}.webpage h2{font-size:18px;margin:0 0 8px;color:var(--app-text-primary)}.webpage p{margin:0;color:var(--app-text-secondary);line-height:1.45}
    .web-card,.poster-wrap{background:var(--app-surface);border:1px solid var(--app-border);border-radius:20px;padding:20px;box-shadow:0 12px 36px #102b4e12}
    .web-card{margin-bottom:16px}.web-owner{display:flex;align-items:center;gap:12px;margin-bottom:14px}.web-avatar{width:52px;height:52px;border-radius:50%;background:var(--app-primary-soft);color:var(--app-primary);display:flex;align-items:center;justify-content:center;font-size:18px;font-weight:800;overflow:hidden;flex:none}.web-avatar img{width:100%;height:100%;object-fit:cover}.web-owner h2{margin:0}.web-status{margin-left:auto;background:var(--app-primary-soft);color:var(--app-success);padding:5px 9px;border-radius:9px;font-size:12px;font-weight:700;white-space:nowrap}
    .web-link{display:flex;gap:10px;align-items:flex-start;background:var(--app-primary-soft);padding:14px;border-radius:13px;overflow-wrap:anywhere;line-height:1.45}.web-link>span{color:var(--app-map-radius)}.web-link a,.web-hint a,.web-related a{color:var(--app-primary)}.web-actions{display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;margin-top:10px}.web-actions button{border:1px solid var(--app-border);border-radius:11px;padding:11px 8px;background:var(--app-surface);color:var(--app-text-primary);font-size:14px;font-weight:700;cursor:pointer}.web-actions .share{background:var(--app-danger);color:white;border-color:var(--app-danger)}.web-hint{font-size:13px;margin-top:10px!important}
    .web-poster{background:var(--app-map-background);border:1px solid var(--app-border);border-radius:20px;padding:20px}.web-poster h2{margin-bottom:8px}.web-poster .print{display:inline-block;border:0;border-radius:11px;background:var(--app-primary);color:white;padding:12px 16px;font-size:14px;font-weight:700;cursor:pointer;margin-top:12px}.web-related{margin-top:18px}.web-related a{display:flex;align-items:center;gap:10px;padding:12px 14px;margin-top:8px;background:var(--app-surface);border:1px solid var(--app-border);border-radius:12px;text-decoration:none;font-weight:600}.web-related a:hover{border-color:var(--app-map-radius)}.poster{width:min(100%,430px);margin:8px auto;background:var(--app-surface);border:1px solid var(--app-border);box-shadow:0 14px 38px #102b4e20}.poster-brand{background:var(--app-primary);color:#fff;text-align:center;padding:27px 20px}.poster-brand small{display:block;font-size:12px;opacity:.85;margin-top:5px}.poster-main{text-align:center;padding:24px}.poster-main .web-avatar{width:74px;height:74px;margin:0 auto 12px;font-size:25px;border:3px solid white;box-shadow:0 1px 8px #102b4e22}.poster-main h2{font-size:22px}.poster-main .subjects{font-size:14px;margin:5px 0 16px}.poster-qr{width:100%;display:grid;grid-template-columns:150px 1fr;align-items:center;gap:14px;text-align:left;border:1px solid var(--app-border);border-radius:14px;padding:12px;background:var(--app-map-background)}.poster-qr img{width:100%;height:auto;image-rendering:pixelated}.poster-qr strong{color:var(--app-primary);font-size:18px;line-height:1.2}.poster-qr small{display:block;color:var(--app-text-secondary);overflow-wrap:anywhere;margin-top:8px}.poster-footer{display:flex;justify-content:space-between;border-top:1px solid var(--app-border);padding:12px 18px;color:var(--app-text-secondary);font-size:11px}
    @media(max-width:850px){.webpage{grid-template-columns:1fr;max-width:560px;padding:24px 16px}.webpage h1{font-size:25px}}
    @media print{body{background:var(--app-surface)}.webpage{display:block;max-width:none;padding:0}.web-dashboard{display:none!important}.poster-wrap{display:block;border:0;box-shadow:none;padding:0}.poster{width:100%;max-width:none;box-shadow:none;border:1px solid var(--app-border);break-inside:avoid}.poster-brand{print-color-adjust:exact;-webkit-print-color-adjust:exact}.poster-qr img{width:160px;height:160px}}
</style>
<main class="webpage">
    <section class="web-dashboard">
        <h1>Your web page</h1>
        <div class="web-card">
            <div class="web-owner">
                <div class="web-avatar">@if($provider->avatar_url)<img src="{{ \App\Support\PublicMediaUrl::resolve($provider->avatar_url) }}" alt="">@else{{ mb_strtoupper(mb_substr($provider->full_name, 0, 2)) }}@endif</div>
                <div><h2>{{ $provider->full_name }}</h2><p>Your page is live</p></div>
                <span class="web-status">● Live</span>
            </div>
            <div class="web-link"><span aria-hidden="true">↗</span><a id="public-page-url" href="{{ $url }}">{{ $url }}</a></div>
            <div class="web-actions">
                <button type="button" id="copy-page">▢ Copy</button>
                <button type="button" class="share" id="share-page">♧ Share</button>
                <button type="button" id="open-page">↗ Open</button>
            </div>
            <p class="web-hint">Share this link on WhatsApp, Facebook or your visiting card.</p>
            <p class="web-hint"><a href="{{ $oldProfileUrl }}">Open your existing profile page</a></p>
        </div>
        <div class="web-poster">
            <h2>Your QR poster</h2>
            <p>Print it for your centre, a school gate or a local shop. People can scan it to see your page.</p>
            <button type="button" class="print" onclick="window.print()">▣ Print my poster</button>
        </div>
        @if($related->isNotEmpty())
            <div class="web-related"><h2>You'll also be listed on</h2>
                @foreach($related as $listing)
                    <a href="{{ \App\Services\SeoDirectory::url($listing->path) }}"><span aria-hidden="true">♧</span>{{ $listing->display_name }} in {{ $listing->city }}<span style="margin-left:auto">↗</span></a>
                @endforeach
            </div>
        @endif
    </section>
    <section class="poster-wrap" aria-label="Printable QR poster preview">
        <article class="poster">
            <header class="poster-brand"><strong>मुझे Nearby Teacher पर ढूँढें</strong><small>{{ $provider->full_name }} · {{ $place }}</small></header>
            <div class="poster-main">
                <div class="web-avatar">@if($provider->avatar_url)<img src="{{ \App\Support\PublicMediaUrl::resolve($provider->avatar_url) }}" alt="">@else{{ mb_strtoupper(mb_substr($provider->full_name, 0, 2)) }}@endif</div>
                <h2>{{ $provider->full_name }}</h2><p>{{ $place }}</p>
                @if($subjectNames)<p class="subjects">{{ $subjectNames }}</p>@endif
                <div class="poster-qr"><img src="{{ $qrUrl }}" alt="QR code linking to {{ $url }}"><div><strong>ऐप से मुझे ढूँढें या WhatsApp करें</strong><small>{{ $url }}</small></div></div>
            </div>
            <footer class="poster-footer"><span>Nearby Teacher</span><span>Scan to view profile</span></footer>
        </article>
    </section>
</main>
<script>
    (() => {
        const url = @js($url);
        const text = @js($shareText);
        document.getElementById('open-page')?.addEventListener('click', () => {
            window.open(url, '_blank', 'noopener');
        });
        document.getElementById('copy-page')?.addEventListener('click', async (event) => {
            try {
                await navigator.clipboard.writeText(url);
                event.currentTarget.textContent = 'Copied';
            } catch {
                window.prompt('Copy your page link', url);
            }
        });
        document.getElementById('share-page')?.addEventListener('click', async () => {
            if (navigator.share) {
                try { await navigator.share({ title: text, text, url }); } catch {}
            } else {
                window.open('https://wa.me/?text=' + encodeURIComponent(text + ' ' + url), '_blank', 'noopener');
            }
        });
    })();
</script>
@endsection
