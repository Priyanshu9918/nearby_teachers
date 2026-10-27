@extends('site.layout')

@php
    $location = collect([$teacher->city, $teacher->state, $teacher->country])->filter()->unique()->implode(', ');
    $subjectNames = $subjects->pluck('name')->implode(', ');
    $heading = $teacher->full_name.' — '.$subjectNames.' '.data_get($t, 'teacherPage.teacherIn', 'teacher in').' '.$location;
    $summary = $teacher->bio ?: $heading;
    $initials = collect(preg_split('/\s+/', trim($teacher->full_name)) ?: [])->filter()->take(2)->map(fn ($word) => mb_substr($word, 0, 1))->implode('');
    $modes = json_decode($teacher->lesson_modes ?: '[]', true) ?: [];
    $labels = ['in_person' => data_get($t, 'teacherPage.inPerson', 'In person'), 'home_tuition' => data_get($t, 'teacherPage.homeTuition', 'Home tuition'), 'online' => data_get($t, 'teacherPage.online', 'Online')];
@endphp

@section('title', $fullProfile ? $teacher->full_name.' — '.data_get($t, 'teacherPage.fullProfile', 'Full profile') : $heading)
@section('description', mb_substr($summary, 0, 155))
@section('meta')
    <link rel="canonical" href="{{ $profileUrl }}">
    <meta property="og:title" content="{{ $heading }}">
    <meta property="og:description" content="{{ mb_substr($summary, 0, 155) }}">
    <meta property="og:type" content="profile">
    <meta property="og:url" content="{{ $profileUrl }}">
    @if ($teacher->avatar_url)<meta property="og:image" content="{{ $teacher->avatar_url }}">@endif
@endsection

@section('content')
<main class="wrap">
    <article class="card profile-card">
        <div class="avatar">
            @if ($teacher->avatar_url)<img src="{{ $teacher->avatar_url }}" alt="{{ $teacher->full_name }}">
            @else{{ $initials }}@endif
        </div>
        @if ($fullProfile)
            <h1>{{ $teacher->full_name }}</h1>
        @else
            <h1><a class="text-link" href="{{ $profileUrl }}">{{ $teacher->full_name }}</a> — {{ $subjectNames }} {{ data_get($t, 'teacherPage.teacherIn', 'teacher in') }} {{ $location }}</h1>
        @endif
        <p class="meta">{{ $location }}</p>
        <div class="tags">@foreach ($subjects as $subject)<span class="tag">{{ $subject->name }}</span>@endforeach</div>
        @if ($teacher->bio)<p class="bio">{{ $teacher->bio }}</p>@endif
        @if ($teacher->rate_per_hour !== null)
            <p>{{ (float) $teacher->rate_per_hour === 0.0 ? data_get($t, 'common.free') : str_replace('{price}', number_format((float) $teacher->rate_per_hour, 2).' '.$teacher->currency, data_get($t, 'website.priceHour')) }}</p>
        @endif
        <p class="meta">
            {{ str_replace('{count}', (string) $teacher->lessons_completed, data_get($t, 'search.lessonsCompleted')) }}
            @if ($teacher->rating_count > 0) · ★ {{ $teacher->rating_avg }} ({{ $teacher->rating_count }})@endif
        </p>

        @if ($fullProfile)
            @if ($modes)
                <h2>{{ data_get($t, 'profile.lessonModes') }}</h2>
                <div class="tags">@foreach ($modes as $mode)@if (isset($labels[$mode]))<span class="tag">{{ $labels[$mode] }}</span>@endif @endforeach</div>
            @endif
            @if ($teacher->trial_days > 0)
                <p>{{ data_get($t, 'teacherPage.trialDays', 'Trial days') }}: {{ $teacher->trial_days }}</p>
            @endif
            @if ($teacher->linkedin_url)
                <h2>{{ data_get($t, 'teacherPage.links', 'Professional links') }}</h2>
                <p><a class="text-link" href="{{ $teacher->linkedin_url }}" target="_blank" rel="noopener noreferrer nofollow ugc">{{ data_get($t, 'profile.linkedIn') }}</a></p>
            @endif
            <h2>{{ data_get($t, 'teacherPage.reviews', 'Reviews') }}</h2>
            @forelse ($reviews as $review)
                <article class="post">
                    <p class="meta">★ {{ $review->rating }}/5 · {{ \Illuminate\Support\Carbon::parse($review->created_at)->locale($lang)->translatedFormat('M j, Y') }}</p>
                    @if ($review->comment)<p>{{ $review->comment }}</p>@endif
                </article>
            @empty
                <p class="meta">{{ data_get($t, 'website.noReviewsYet') }}</p>
            @endforelse
            <nav class="contact" aria-label="{{ data_get($t, 'teacherPage.reviews', 'Reviews') }}">
                @if ($reviews->previousPageUrl())<a class="text-link" href="{{ $reviews->previousPageUrl() }}">{{ data_get($t, 'teacherPage.previous', 'Previous') }}</a>@endif
                @if ($reviews->nextPageUrl())<a class="text-link" href="{{ $reviews->nextPageUrl() }}">{{ data_get($t, 'teacherPage.next', 'Next') }}</a>@endif
            </nav>
        @else
            <p><a class="btn" href="{{ $profileUrl }}">{{ data_get($t, 'teacherPage.viewFullProfile', 'View full profile') }}</a></p>
        @endif
        <p><a class="btn" href="{{ $downloadHref }}">{{ data_get($t, 'website.downloadToMessage') }}</a></p>
        <p class="meta">{{ data_get($t, 'website.contactDetailsInApp') }}</p>
    </article>
    <p class="footer-note"><a href="{{ route('website.directory') }}">{{ data_get($t, 'common.appName') }}</a></p>
</main>
@endsection
