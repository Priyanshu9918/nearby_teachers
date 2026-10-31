@extends('site.layout')
@section('documentLanguage', $language)
@section('documentDirection', in_array($language, ['ar', 'ur'], true) ? 'rtl' : 'ltr')
@php
    $tr = fn ($key, $values = []) => \App\Services\SeoDirectory::text($labels, $key, $values);
    $subjectNames = $subjects->pluck('name')->implode(', ');
    $title = $teacher->full_name.' — '.$tr('city_title', ['subject' => $subjects->take(2)->pluck('name')->implode(', '), 'city' => $teacher->city]);
    $description = \Illuminate\Support\Str::limit($tr('city_description', ['subject' => $subjectNames, 'city' => $teacher->city]), 160);
    $canonicalUrl = $url;
    $person = ['@type' => 'Person', 'name' => $teacher->full_name, 'url' => $url, 'knowsAbout' => $subjects->pluck('name')->all()];
    if ($teacher->avatar_url) $person['image'] = \App\Support\PublicMediaUrl::resolve($teacher->avatar_url);
    if ($teacher->linkedin_url) $person['sameAs'] = [$teacher->linkedin_url];
    $schema = ['@context' => 'https://schema.org', '@type' => 'WebPage', 'name' => $title, 'url' => $url, 'mainEntity' => $person];
@endphp
@section('title', $title)
@section('description', $description)
@section('meta')@include('seo.meta')@endsection
@section('content')
<div class="teacher-page directory-page">
@include('seo.partials.header', ['headerCountry' => $teacher->seo_country, 'providerType' => 'teacher', 'providerSlug' => $teacher->slug])
<main class="teacher-shell">
<nav class="teacher-crumbs" aria-label="Breadcrumb"><a href="{{ \App\Services\SeoDirectory::url('/directory') }}">{{ $labels['teachers'] }}</a><span aria-hidden="true">/</span><span>{{ $teacher->city }}</span><span aria-hidden="true">/</span><span aria-current="page">{{ $teacher->full_name }}</span></nav>
<header class="teacher-hero"><div class="avatar" aria-hidden="true">@if ($teacher->avatar_url)<img src="{{ \App\Support\PublicMediaUrl::resolve($teacher->avatar_url) }}" alt="" width="96" height="96">@else{{ mb_substr($teacher->full_name, 0, 1) }}@endif</div><div><p class="teacher-kicker">{{ $labels['profile'] }}</p><h1>{{ $teacher->full_name }}</h1><p class="teacher-subtitle">{{ $subjectNames }}</p><p class="teacher-location">{{ collect([$teacher->city, $teacher->state, $teacher->country])->filter()->unique()->implode(', ') }}</p></div></header>
<div class="teacher-columns"><div>
@if ($teacher->bio)<section class="teacher-panel"><h2>{{ $labels['about'] }}</h2><p class="bio">{{ $teacher->bio }}</p></section>@endif
<section class="teacher-panel"><h2>{{ $labels['subjects'] }}</h2><div class="tags">@foreach ($subjects as $subject)<span class="tag">{{ $subject->name }}</span>@endforeach</div><h2 class="mode-heading">{{ $labels['modes'] }}</h2><div class="tags">@foreach (json_decode($teacher->lesson_modes ?: '[]', true) as $mode)@if (isset($labels[$mode]))<span class="tag">{{ $labels[$mode] }}</span>@endif @endforeach</div><div class="teacher-stats"><div class="teacher-stat"><strong>{{ $teacher->lessons_completed }}</strong><span>{{ $labels['lessons'] }}</span></div><div class="teacher-stat">@if ($teacher->rating_count > 0)<strong>★ {{ $teacher->rating_avg }}</strong><span>{{ $teacher->rating_count }} {{ $labels['reviews'] }}</span>@else<span>{{ $labels['no_reviews'] }}</span>@endif</div></div></section>
@if ($teacher->linkedin_url)<section class="teacher-panel"><a class="text-link" href="{{ $teacher->linkedin_url }}" target="_blank" rel="noopener noreferrer nofollow ugc">{{ $labels['linkedin'] }} ↗</a></section>@endif
<section class="teacher-panel"><h2>{{ $labels['reviews'] }}</h2>@forelse ($reviews as $review)<article class="teacher-review"><span class="teacher-review-stars">★ {{ $review->rating }}/5</span><time class="meta" datetime="{{ \Illuminate\Support\Carbon::parse($review->created_at)->toDateString() }}"> · {{ \Illuminate\Support\Carbon::parse($review->created_at)->locale($language)->translatedFormat('M j, Y') }}</time>@if ($review->comment)<p>{{ $review->comment }}</p>@endif</article>@empty<p class="teacher-note">{{ $labels['no_reviews'] }}</p>@endforelse
@if($reviews->hasPages())<nav class="teacher-pagination">@if ($reviews->previousPageUrl())<a class="text-link" href="{{ $reviews->previousPageUrl() }}">{{ $labels['previous'] }}</a>@endif @if ($reviews->nextPageUrl())<a class="text-link" href="{{ $reviews->nextPageUrl() }}">{{ $labels['next'] }}</a>@endif</nav>@endif</section>
@if ($related->isNotEmpty())<section class="teacher-panel"><h2>{{ $labels['more'] }}</h2><div class="tags">@foreach ($related as $page)<a class="tag text-link" href="{{ \App\Services\SeoDirectory::url($page->path) }}">{{ $page->display_name }} · {{ $page->city }}</a>@endforeach</div></section>@endif
</div><aside class="teacher-panel teacher-message">
@if ($teacher->rate_per_hour !== null)<p class="teacher-price">{{ number_format((float) $teacher->rate_per_hour, 2) }} {{ $teacher->currency }}</p><p class="meta">{{ $labels['rate'] }}</p>@endif
@if ($teacher->trial_days > 0)<p class="teacher-trial">{{ $labels['trial'] }}: {{ $teacher->trial_days }}</p>@endif
<a class="btn" href="{{ $downloadHref }}">Download the app to message</a><p class="meta">Send messages through the app.</p>
</aside></div></main></div>
@endsection
