@extends('site.layout')
@section('documentLanguage', $language)
@section('documentDirection', $direction)
@section('title', $title)
@section('description', $description)
@php
    $canonicalUrl = $url.($teachers->currentPage() > 1 ? '?page='.$teachers->currentPage() : '');
    $schema = ['@context' => 'https://schema.org', '@type' => 'CollectionPage', 'name' => $title,
        'url' => $canonicalUrl, 'description' => $description];
@endphp
@section('meta')@include('seo.meta')@endsection
@section('content')
<div class="teacher-page">
    <header class="teacher-topbar"><div><a class="teacher-brand" href="{{ \App\Services\SeoDirectory::url('/directory') }}">Nearby Teacher</a><span>{{ $labels['directory'] }}</span></div></header>
    <main class="teacher-shell">
        <nav class="teacher-crumbs" aria-label="Breadcrumb">
            <a href="{{ \App\Services\SeoDirectory::url('/directory') }}">{{ $labels['teachers'] }}</a>
            @foreach ($breadcrumbs as $crumb)<span aria-hidden="true">/</span>@if ($crumb['url'])<a href="{{ $crumb['url'] }}">{{ $crumb['label'] }}</a>@else<span aria-current="page">{{ $crumb['label'] }}</span>@endif @endforeach
        </nav>
        <header class="teacher-hero"><div><p class="teacher-kicker">{{ $labels['teachers'] }}</p><h1>{{ $title }}</h1><p class="teacher-note">{{ $description }}</p><p>{{ \App\Services\SeoDirectory::text($labels, 'count', ['count' => $teachers->total()]) }}</p></div></header>
        <nav class="seo-languages" aria-label="{{ $labels['language'] }}">@foreach ($variants as $variant)<a class="text-link" href="{{ $variant['url'] }}" hreflang="{{ $variant['code'] }}" @if ($variant['language'] === $language) aria-current="page" @endif>{{ strtoupper($variant['language']) }}</a>@endforeach</nav>

        @if ($children)
            <section aria-labelledby="location-links-title"><h2 id="location-links-title">{{ $labels['browse'] ?? 'Browse locations and subjects' }}</h2><div class="tags">
                @foreach ($children as $child)<a class="tag" href="{{ $child->url }}">{{ $child->label }}</a>@endforeach
            </div></section>
        @endif

        <div class="seo-list">@foreach ($teachers as $item)
            <article class="teacher-panel"><div class="seo-card-head"><div class="avatar" aria-hidden="true">@if ($item->avatar_url)<img src="{{ $item->avatar_url }}" alt="" loading="lazy" width="64" height="64">@else{{ mb_substr($item->full_name, 0, 1) }}@endif</div><h2><a class="text-link" href="{{ \App\Services\SeoDirectory::url('/teacher/'.$item->public_page_slug) }}">{{ $item->full_name }}</a></h2></div>
                <p class="teacher-note">{{ collect([$item->locality, $item->district, $item->city])->filter()->unique()->implode(', ') }}</p>
                @if ($item->rate_per_hour !== null)<p>{{ number_format((float) $item->rate_per_hour, 2) }} {{ $item->currency }} · {{ $labels['rate'] }}</p>@endif
                @if ($item->rating_count > 0)<p>★ {{ $item->rating_avg }} ({{ $item->rating_count }})</p>@endif
                <p>{{ $labels['referrals'] ?? 'Referral points' }}: {{ $item->referral_points }}</p>
                <div class="tags">@foreach (json_decode($item->lesson_modes ?: '[]', true) as $mode)@if(isset($labels[$mode]))<span class="tag">{{ $labels[$mode] }}</span>@endif @endforeach</div>
                <a class="text-link" href="{{ \App\Services\SeoDirectory::url('/teacher/'.$item->public_page_slug) }}">{{ $labels['profile'] }} →</a>
            </article>
        @endforeach</div>
        <nav class="seo-pages" aria-label="Pagination">@if ($teachers->previousPageUrl())<a class="text-link" href="{{ $teachers->previousPageUrl() }}" rel="prev">{{ $labels['previous'] }}</a>@endif @if ($teachers->nextPageUrl())<a class="text-link" href="{{ $teachers->nextPageUrl() }}" rel="next">{{ $labels['next'] }}</a>@endif</nav>
    </main>
</div>
@endsection
