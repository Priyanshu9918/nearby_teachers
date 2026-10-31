<link rel="canonical" href="{{ $canonicalUrl }}">
<meta property="og:title" content="{{ $title }}">
<meta property="og:description" content="{{ $description }}">
<meta property="og:url" content="{{ $canonicalUrl }}">
<meta property="og:type" content="{{ isset($teacher) || isset($institute) ? 'profile' : 'website' }}">
<meta name="robots" content="{{ $robots ?? 'index,follow' }}">
<meta name="twitter:card" content="summary">
<meta name="twitter:title" content="{{ $title }}">
<meta name="twitter:description" content="{{ $description }}">
@foreach ($variants ?? [] as $variant)
<link rel="alternate" hreflang="{{ $variant['code'] }}" href="{{ $variant['url'] }}">
@endforeach
@if ((isset($teacher) && $teacher->avatar_url) || (isset($institute) && $institute->avatar_url))
<meta property="og:image" content="{{ \App\Support\PublicMediaUrl::resolve(isset($teacher) ? $teacher->avatar_url : $institute->avatar_url) }}">
@endif
<script type="application/ld+json">{!! json_encode($schema, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) !!}</script>
@include('site.partials.teacher-style')
<style>
.seo-list{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,280px),1fr));gap:18px;margin:24px 0}
.seo-list.directory-results{grid-template-columns:1fr;gap:10px;margin:12px 0}
.seo-list .teacher-panel{margin:0}.seo-list h2{line-height:1.5}
.seo-languages{display:flex;gap:14px;flex-wrap:wrap;margin:18px 0}
.seo-pages{display:flex;justify-content:space-between;margin-top:24px}
.seo-card-head{display:flex;align-items:center;gap:14px}.seo-card-head .avatar{flex-shrink:0}
.directory-kind{display:inline-block;padding:3px 7px;border-radius:6px;background:var(--app-primary-soft);color:var(--app-primary);font-size:11px;font-weight:650}
.directory-search{padding:16px;background:var(--app-surface);border:1px solid var(--app-border);border-radius:16px;box-shadow:0 5px 18px #102b4e0a}
.directory-search input[type=search],.directory-search select{min-height:44px}
.directory-search button{min-height:44px;cursor:pointer}
.directory-search button:hover,.directory-search button:focus-visible{filter:brightness(1.12)}
.directory-search :focus-visible,.directory-pagination a:focus-visible,.directory-card a:focus-visible{outline:3px solid #2f80ff;outline-offset:2px}
.directory-active-filters{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin:0 0 14px;color:var(--app-text-secondary);font-size:12px}
.directory-active-filters strong{color:var(--app-text-primary)}
.directory-active-filters span{padding:5px 9px;border-radius:999px;background:var(--app-primary-soft);color:var(--app-primary)}
.directory-tags .directory-free-trial{background:#e9f7ef;color:#187647;font-weight:700}
.directory-card{transition:border-color .15s ease,box-shadow .15s ease}
.directory-card:hover{border-color:#c7d4e3;box-shadow:0 8px 24px #102b4e12}
.directory-empty{border:1px solid var(--app-border);border-radius:16px;padding:28px;background:var(--app-surface)}
a{overflow-wrap:anywhere}
@media(max-width:600px){.directory-search{padding:12px}.directory-active-filters{align-items:flex-start}.directory-pagination{gap:12px}}
</style>
