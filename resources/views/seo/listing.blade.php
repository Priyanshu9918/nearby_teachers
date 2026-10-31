@extends('site.layout')
@section('documentLanguage', $language)
@section('documentDirection', in_array($language, ['ar', 'ur'], true) ? 'rtl' : 'ltr')
@section('title', $title)
@section('description', $description)
@php
    $schema = ['@context' => 'https://schema.org', '@type' => 'CollectionPage', 'name' => $title,
        'url' => $canonicalUrl, 'description' => $description,
        'mainEntity' => ['@type' => 'ItemList', 'numberOfItems' => $providers->count(),
            'itemListElement' => $providers->getCollection()->values()->map(fn ($item, $index) => [
                '@type' => 'ListItem',
                'position' => (($providers->currentPage() - 1) * $providers->perPage()) + $index + 1,
                'name' => $item->full_name,
                'url' => \App\Services\SeoDirectory::url('/'.$item->provider_type.'/'.$item->public_page_slug),
            ])->all()]];
@endphp
@section('meta')@include('seo.meta')@endsection
@section('content')
<div class="teacher-page directory-page">
@include('seo.partials.header', ['headerCountry' => $page->country])
<main class="directory-shell">
<nav class="teacher-crumbs" aria-label="Breadcrumb"><a href="{{ \App\Services\SeoDirectory::url('/directory') }}">{{ $labels['directory'] }}</a><span aria-hidden="true">/</span><span>{{ strtoupper($page->country) }}</span><span aria-hidden="true">/</span><span aria-current="page">{{ $page->kind === 'online' ? $labels['online'] : $page->city }}</span></nav>
<header class="teacher-hero"><div><p class="teacher-kicker">{{ $labels['directory'] }}</p><h1>{{ $title }}</h1><p class="teacher-note">{{ $description }}</p><p>{{ \App\Services\SeoDirectory::text($labels, 'count', ['count' => $providers->total()]) }}</p></div></header>
<section class="directory-results seo-list" aria-label="{{ $title }}">@foreach ($providers as $item)
@php($profilePath = '/'.$item->provider_type.'/'.$item->public_page_slug)
<article class="directory-card">
    <div class="directory-card-heading">
        <div class="directory-avatar" aria-hidden="true">@if ($item->avatar_url)<img src="{{ \App\Support\PublicMediaUrl::resolve($item->avatar_url) }}" alt="" loading="lazy" width="48" height="48">@else{{ mb_substr($item->full_name, 0, 1) }}@endif</div>
        <div><h2><a href="{{ \App\Services\SeoDirectory::url($profilePath) }}">{{ $item->full_name }}</a></h2><span class="directory-kind">{{ $item->provider_type === 'institute' ? 'Institute' : 'Teacher' }}</span></div>
    </div>
    <p class="directory-place">@if ($item->rating_count > 0)★ {{ number_format((float) $item->rating_avg, 1) }} ({{ $item->rating_count }}) <span>·</span> @else{{ $labels['no_reviews'] }} <span>·</span> @endif{{ $item->city }}</p>
    @if ($item->subject_names)<p class="directory-subjects">{{ $item->subject_names }}</p>@endif
    <div class="directory-tags">@foreach (json_decode($item->lesson_modes ?: '[]', true) as $mode)@if(isset($labels[$mode]))<span>{{ $labels[$mode] }}</span>@endif @endforeach @if($item->trial_days > 0)<span>{{ $labels['trial'] }}</span>@endif</div>
    @if ($item->rate !== null)<p class="directory-rate">{{ number_format((float) $item->rate, 0) }} {{ $item->currency }}/hour · {{ $labels['rate'] }}</p>@endif
    <div class="directory-actions"><a class="directory-contact" href="{{ route('download', ['type' => $item->provider_type, 'slug' => $item->public_page_slug]) }}">Get the app to contact</a><a class="directory-profile" href="{{ \App\Services\SeoDirectory::url($profilePath) }}">{{ $labels['profile'] }}</a></div>
</article>
@endforeach</section>
<nav class="seo-pages" aria-label="Pagination">@if ($providers->previousPageUrl())<a class="text-link" href="{{ $providers->previousPageUrl() }}" rel="prev">{{ $labels['previous'] }}</a>@endif @if ($providers->nextPageUrl())<a class="text-link" href="{{ $providers->nextPageUrl() }}" rel="next">{{ $labels['next'] }}</a>@endif</nav>
</main></div>
@endsection
