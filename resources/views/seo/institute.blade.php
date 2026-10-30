@extends('site.layout')
@section('documentLanguage', $language)
@section('documentDirection', in_array($language, ['ar', 'ur'], true) ? 'rtl' : 'ltr')
@php
    $tr = fn ($key, $values = []) => \App\Services\SeoDirectory::text($labels, $key, $values);
    $subjectNames = $subjects->pluck('name')->implode(', ');
    $title = $institute->full_name.' — '.$subjectNames.' '.$institute->city;
    $description = \Illuminate\Support\Str::limit($institute->description ?: $title, 160);
    $canonicalUrl = $url;
    $organization = ['@type' => 'EducationalOrganization', 'name' => $institute->full_name, 'url' => $url,
        'address' => ['@type' => 'PostalAddress', 'addressLocality' => $institute->city,
            'addressRegion' => $institute->state, 'addressCountry' => $institute->country]];
    if ($institute->website_url) $organization['sameAs'] = [$institute->website_url];
    if ($institute->avatar_url) $organization['image'] = $institute->avatar_url;
    $schema = ['@context' => 'https://schema.org', '@type' => 'WebPage', 'name' => $title, 'url' => $url,
        'description' => $description, 'mainEntity' => $organization];
@endphp
@section('title', $title)
@section('description', $description)
@section('meta')@include('seo.meta')@endsection
@section('content')
<div class="teacher-page directory-page">
@include('seo.partials.header', ['headerCountry' => $institute->seo_country, 'providerType' => 'institute', 'providerSlug' => $institute->slug])
<main class="teacher-shell">
<nav class="teacher-crumbs" aria-label="Breadcrumb"><a href="{{ \App\Services\SeoDirectory::url('/directory') }}">{{ $labels['directory'] }}</a><span aria-hidden="true">/</span><span>{{ $institute->city }}</span><span aria-hidden="true">/</span><span aria-current="page">{{ $institute->full_name }}</span></nav>
<header class="teacher-hero"><div class="avatar" aria-hidden="true">@if ($institute->avatar_url)<img src="{{ $institute->avatar_url }}" alt="" width="96" height="96">@else{{ mb_substr($institute->full_name, 0, 1) }}@endif</div><div><p class="teacher-kicker">Institute</p><h1>{{ $institute->full_name }}</h1><p class="teacher-subtitle">{{ $subjectNames }}</p><p class="teacher-location">{{ collect([$institute->city, $institute->state, $institute->country])->filter()->unique()->implode(', ') }}</p></div></header>
<div class="teacher-columns"><div>
@if ($institute->description)<section class="teacher-panel"><h2>About</h2><p class="bio">{{ $institute->description }}</p></section>@endif
<section class="teacher-panel"><h2>{{ $labels['subjects'] }}</h2><div class="tags">@foreach ($subjects as $subject)<span class="tag">{{ $subject->name }}</span>@endforeach</div>
<h2 class="mode-heading">{{ $labels['modes'] }}</h2><div class="tags">@foreach (json_decode($institute->lesson_modes ?: '[]', true) as $mode)@if (isset($labels[$mode]))<span class="tag">{{ $labels[$mode] }}</span>@endif @endforeach</div>
<div class="teacher-stats"><div class="teacher-stat"><strong>{{ $institute->lessons_completed }}</strong><span>{{ $labels['lessons'] }}</span></div><div class="teacher-stat">@if ($institute->rating_count > 0)<strong>★ {{ $institute->rating_avg }}</strong><span>{{ $institute->rating_count }} {{ $labels['reviews'] }}</span>@else<span>{{ $labels['no_reviews'] }}</span>@endif</div></div></section>
@if ($institute->website_url)<section class="teacher-panel"><a class="text-link" href="{{ $institute->website_url }}" target="_blank" rel="noopener noreferrer nofollow">{{ $labels['directory'] }} ↗</a></section>@endif
<section class="teacher-panel"><h2>{{ $labels['reviews'] }}</h2>@forelse ($reviews as $review)<article class="teacher-review"><span class="teacher-review-stars">★ {{ $review->rating }}/5</span>@if ($review->comment)<p>{{ $review->comment }}</p>@endif</article>@empty<p class="teacher-note">{{ $labels['no_reviews'] }}</p>@endforelse</section>
@if ($related->isNotEmpty())<section class="teacher-panel"><h2>{{ $labels['more'] }}</h2><div class="tags">@foreach ($related as $page)<a class="tag text-link" href="{{ \App\Services\SeoDirectory::url($page->path) }}">{{ $page->display_name }} · {{ $page->city }}</a>@endforeach</div></section>@endif
</div><aside class="teacher-panel teacher-message">
@if ($institute->starting_rate !== null)<p class="teacher-price">{{ number_format((float) $institute->starting_rate, 2) }} {{ $institute->currency }}</p><p class="meta">Starting rate</p>@endif
@if ($institute->trial_days > 0)<p class="teacher-trial">{{ $labels['trial'] }}: {{ $institute->trial_days }}</p>@endif
<a class="btn" href="{{ $downloadHref }}">Download the app to contact</a><p class="meta">Send messages through the app.</p>
</aside></div></main></div>
@endsection
