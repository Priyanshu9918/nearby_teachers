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
<div class="teacher-page"><header class="teacher-topbar"><div><a class="teacher-brand" href="{{ \App\Services\SeoDirectory::url('/directory') }}">Nearby Teacher</a><span>{{ $labels['directory'] }}</span></div></header>
<main class="teacher-shell">
<nav class="teacher-crumbs" aria-label="Breadcrumb"><a href="{{ \App\Services\SeoDirectory::url('/directory') }}">{{ $labels['teachers'] }}</a><span aria-hidden="true">/</span><span>{{ strtoupper($page->country) }}</span><span aria-hidden="true">/</span><span aria-current="page">{{ $page->kind === 'online' ? $labels['online'] : $page->city }}</span></nav>
<header class="teacher-hero"><div><p class="teacher-kicker">{{ $labels['teachers'] }}</p><h1>{{ $title }}</h1><p class="teacher-note">{{ $description }}</p><p>{{ \App\Services\SeoDirectory::text($labels, 'count', ['count' => $teachers->total()]) }}</p></div></header>
<form class="seo-languages" method="get" action="{{ request()->url() }}"><label for="listing-language">{{ $labels['language'] }}</label><select id="listing-language" name="lang" onchange="this.form.submit()">@foreach (config('seo.countries.'.$page->country.'.languages') as $code)<option value="{{ $code }}" @selected($language === $code)>{{ config('seo.language_names.'.$code, strtoupper($code)) }}</option>@endforeach</select></form>
<div class="seo-list">@foreach ($teachers as $item)
<article class="teacher-panel"><div class="seo-card-head"><div class="avatar" aria-hidden="true">@if ($item->avatar_url)<img src="{{ $item->avatar_url }}" alt="" loading="lazy" width="64" height="64">@else{{ mb_substr($item->full_name, 0, 1) }}@endif</div><h2><a class="text-link" href="{{ \App\Services\SeoDirectory::url('/teacher/'.$item->public_page_slug) }}">{{ $item->full_name }}</a></h2></div>
<p class="teacher-note">{{ $item->city }}</p>
@if ($item->rate_per_hour !== null)<p>{{ number_format((float) $item->rate_per_hour, 2) }} {{ $item->currency }} · {{ $labels['rate'] }}</p>@endif
@if ($item->rating_count > 0)<p>★ {{ $item->rating_avg }} ({{ $item->rating_count }})</p>@endif
<div class="tags">@foreach (json_decode($item->lesson_modes ?: '[]', true) as $mode)@if(isset($labels[$mode]))<span class="tag">{{ $labels[$mode] }}</span>@endif @endforeach</div>
<a class="text-link" href="{{ \App\Services\SeoDirectory::url('/teacher/'.$item->public_page_slug) }}">{{ $labels['profile'] }} →</a></article>
@endforeach</div>
<nav class="seo-pages" aria-label="Pagination">@if ($teachers->previousPageUrl())<a class="text-link" href="{{ $teachers->previousPageUrl() }}" rel="prev">{{ $labels['previous'] }}</a>@endif @if ($teachers->nextPageUrl())<a class="text-link" href="{{ $teachers->nextPageUrl() }}" rel="next">{{ $labels['next'] }}</a>@endif</nav>
</main></div>
@endsection
