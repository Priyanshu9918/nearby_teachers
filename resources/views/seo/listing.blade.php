@extends('site.layout')
@section('documentLanguage', $language)
@section('documentDirection', in_array($language, ['ar', 'ur'], true) ? 'rtl' : 'ltr')
@section('title', $title)
@section('description', $description)
@php
    $schema = ['@context' => 'https://schema.org', '@type' => 'CollectionPage', 'name' => $title, 'url' => $canonicalUrl, 'description' => $description];
@endphp
@section('meta')@include('seo.meta')@endsection
@section('content')
<div class="teacher-page directory-page">
@include('seo.partials.header', ['headerCountry' => $page->country])
<main class="directory-shell">
<nav class="teacher-crumbs" aria-label="Breadcrumb"><a href="{{ \App\Services\SeoDirectory::url('/directory') }}">{{ $labels['directory'] }}</a><span aria-hidden="true">/</span><span>{{ strtoupper($page->country) }}</span><span aria-hidden="true">/</span><span aria-current="page">{{ $page->kind === 'online' ? $labels['online'] : $page->city }}</span></nav>
<header class="teacher-hero"><div><p class="teacher-kicker">{{ $labels['directory'] }}</p><h1>{{ $title }}</h1><p class="teacher-note">{{ $description }}</p><p>{{ \App\Services\SeoDirectory::text($labels, 'count', ['count' => $providers->total()]) }}</p></div></header>
<div class="seo-list">@foreach ($providers as $item)
@php($profilePath = '/'.$item->provider_type.'/'.$item->public_page_slug)
<article class="teacher-panel"><div class="seo-card-head"><div class="avatar" aria-hidden="true">@if ($item->avatar_url)<img src="{{ $item->avatar_url }}" alt="" loading="lazy" width="64" height="64">@else{{ mb_substr($item->full_name, 0, 1) }}@endif</div><div><h2><a class="text-link" href="{{ \App\Services\SeoDirectory::url($profilePath) }}">{{ $item->full_name }}</a></h2><span class="teacher-note">{{ $item->provider_type === 'institute' ? 'Institute' : 'Teacher' }}</span></div></div>
<p class="teacher-note">{{ $item->city }}</p>
@if ($item->rate !== null)<p>{{ number_format((float) $item->rate, 2) }} {{ $item->currency }} · {{ $labels['rate'] }}</p>@endif
@if ($item->rating_count > 0)<p>★ {{ $item->rating_avg }} ({{ $item->rating_count }})</p>@endif
<div class="tags">@foreach (json_decode($item->lesson_modes ?: '[]', true) as $mode)@if(isset($labels[$mode]))<span class="tag">{{ $labels[$mode] }}</span>@endif @endforeach</div>
<a class="text-link" href="{{ \App\Services\SeoDirectory::url($profilePath) }}">{{ $labels['profile'] }} →</a></article>
@endforeach</div>
<nav class="seo-pages" aria-label="Pagination">@if ($providers->previousPageUrl())<a class="text-link" href="{{ $providers->previousPageUrl() }}" rel="prev">{{ $labels['previous'] }}</a>@endif @if ($providers->nextPageUrl())<a class="text-link" href="{{ $providers->nextPageUrl() }}" rel="next">{{ $labels['next'] }}</a>@endif</nav>
</main></div>
@endsection
