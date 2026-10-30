<link rel="canonical" href="{{ $canonicalUrl }}">
<meta property="og:title" content="{{ $title }}">
<meta property="og:description" content="{{ $description }}">
<meta property="og:url" content="{{ $canonicalUrl }}">
<meta property="og:type" content="{{ isset($teacher) || isset($institute) ? 'profile' : 'website' }}">
<meta name="twitter:card" content="summary">
<meta name="twitter:title" content="{{ $title }}">
<meta name="twitter:description" content="{{ $description }}">
@foreach ($variants ?? [] as $variant)
<link rel="alternate" hreflang="{{ $variant['code'] }}" href="{{ $variant['url'] }}">
@endforeach
@if ((isset($teacher) && $teacher->avatar_url) || (isset($institute) && $institute->avatar_url))
<meta property="og:image" content="{{ isset($teacher) ? $teacher->avatar_url : $institute->avatar_url }}">
@endif
<script type="application/ld+json">{!! json_encode($schema, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) !!}</script>
@include('site.partials.teacher-style')
<style>.seo-list{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,280px),1fr));gap:18px;margin:24px 0}.seo-list.directory-results{grid-template-columns:1fr;gap:10px;margin:12px 0}.seo-list .teacher-panel{margin:0}.seo-list h2{line-height:1.5}.seo-languages{display:flex;gap:14px;flex-wrap:wrap;margin:18px 0}.seo-pages{display:flex;justify-content:space-between;margin-top:24px}.seo-card-head{display:flex;align-items:center;gap:14px}.seo-card-head .avatar{flex-shrink:0}.directory-kind{display:inline-block;padding:3px 7px;border-radius:6px;background:var(--app-primary-soft);color:var(--app-primary);font-size:11px;font-weight:650}a{overflow-wrap:anywhere}</style>
