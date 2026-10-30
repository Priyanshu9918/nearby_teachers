@extends('site.layout')
@section('documentLanguage', $language)
@section('documentDirection', in_array($language, ['ar', 'ur'], true) ? 'rtl' : 'ltr')
@section('title', $labels['directory'])
@section('description', $labels['teachers'])
@php
    $title = $labels['directory'];
    $description = $labels['teachers'];
    $canonicalUrl = \App\Services\SeoDirectory::url('/directory');
    $schema = ['@context' => 'https://schema.org', '@type' => 'CollectionPage', 'name' => $title, 'url' => $canonicalUrl];
    $variants = [];
@endphp
@section('meta')@include('seo.meta')@endsection
@section('content')
<div class="teacher-page directory-page directory-hub">
    @include('seo.partials.header', ['headerCountry' => null])
    <main class="directory-shell">
        <nav class="teacher-crumbs" aria-label="Breadcrumb"><a href="{{ \App\Services\SeoDirectory::url('/directory') }}">{{ $labels['directory'] }}</a></nav>
        <header class="directory-heading">
            <h1>{{ $labels['directory'] }}</h1>
            <p>{{ $labels['teachers'] }}</p>
        </header>
        <section id="subjects" class="directory-results" aria-label="{{ $labels['directory'] }}">
            @forelse ($pages as $page)
                @php
                    $pageUrl = \App\Services\SeoDirectory::url($page->path);
                    $allowedLanguages = config('seo.countries.'.$page->country.'.languages', []);
                    if ($language !== $page->language && in_array($language, $allowedLanguages, true)) $pageUrl .= '?lang='.rawurlencode($language);
                @endphp
                <a class="directory-card directory-hub-card" href="{{ $pageUrl }}">
                    <span>{{ $page->display_name }} · {{ $page->kind === 'online' ? ($labels['online'] ?? 'Online') : $page->city }} · {{ strtoupper($page->country) }}</span>
                    <span>{{ strtoupper($page->language) }} →</span>
                </a>
            @empty
                <p class="directory-empty">No published listings yet.</p>
            @endforelse
        </section>
        <nav class="seo-pages directory-pagination" aria-label="Pagination">
            @if ($pages->previousPageUrl())<a href="{{ $pages->previousPageUrl() }}">{{ $labels['previous'] }}</a>@endif
            @if ($pages->nextPageUrl())<a href="{{ $pages->nextPageUrl() }}">{{ $labels['next'] }}</a>@endif
        </nav>
    </main>
</div>
@endsection
