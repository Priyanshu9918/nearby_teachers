@extends('site.layout')
@section('documentLanguage', 'en')
@section('documentDirection', 'ltr')

@php
    $location = collect([$teacher->city, $teacher->state, $teacher->country])->filter()->unique()->implode(', ');
    $subjectNames = $subjects->pluck('name')->implode(', ');
    $titleSubjects = $subjects->take(2)->pluck('name')->implode(' & ');
    $pageTitle = $teacher->full_name.' — '.$titleSubjects.' Teacher in '.$teacher->city;
    $modes = json_decode($teacher->lesson_modes ?: '[]', true) ?: [];
    $labels = ['in_person' => 'In person', 'home_tuition' => 'Home tuition', 'online' => 'Online'];
    $modeNames = collect($modes)->map(fn ($mode) => $labels[$mode] ?? null)->filter()->implode(', ');
    $summary = \Illuminate\Support\Str::limit(trim(strip_tags($teacher->full_name.' teaches '.$subjectNames.' in '.$location.'. '.($modeNames ? $modeNames.' lessons. ' : '').($teacher->bio ?? ''))), 160, '…');
    $initials = collect(preg_split('/\s+/', trim($teacher->full_name)) ?: [])->filter()->take(2)->map(fn ($word) => mb_substr($word, 0, 1))->implode('');
    $cityUrl = route('website.city', ['city' => $teacher->city]);
    $person = ['@type' => 'Person', '@id' => $profileUrl.'#teacher', 'name' => $teacher->full_name,
        'url' => $profileUrl, 'knowsAbout' => $subjects->pluck('name')->all()];
    if ($teacher->bio) $person['description'] = strip_tags($teacher->bio);
    if ($teacher->avatar_url) $person['image'] = $teacher->avatar_url;
    if ($teacher->linkedin_url) $person['sameAs'] = [$teacher->linkedin_url];
    $structuredData = ['@context' => 'https://schema.org', '@graph' => [
        $person,
        ['@type' => 'WebPage', '@id' => $profileUrl.'#page', 'url' => $profileUrl, 'name' => $pageTitle,
            'description' => $summary, 'mainEntity' => ['@id' => $profileUrl.'#teacher']],
        ['@type' => 'BreadcrumbList', 'itemListElement' => [
            ['@type' => 'ListItem', 'position' => 1, 'name' => 'Teachers', 'item' => route('website.directory')],
            ['@type' => 'ListItem', 'position' => 2, 'name' => $teacher->city, 'item' => $cityUrl],
            ['@type' => 'ListItem', 'position' => 3, 'name' => $teacher->full_name, 'item' => $profileUrl],
        ]],
    ]];
@endphp

@section('title', $pageTitle)
@section('description', $summary)
@section('meta')
    <link rel="canonical" href="{{ $profileUrl }}">
    <meta property="og:title" content="{{ $pageTitle }}">
    <meta property="og:description" content="{{ $summary }}">
    <meta property="og:type" content="profile">
    <meta property="og:url" content="{{ $profileUrl }}">
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="{{ $pageTitle }}">
    <meta name="twitter:description" content="{{ $summary }}">
    @if ($teacher->avatar_url)
        <meta property="og:image" content="{{ $teacher->avatar_url }}">
        <meta name="twitter:image" content="{{ $teacher->avatar_url }}">
    @endif
    <script type="application/ld+json">{!! json_encode($structuredData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) !!}</script>
    @include('site.partials.teacher-style')
@endsection

@section('content')
<div class="teacher-page">
    <header class="teacher-topbar"><div>
        <a class="teacher-brand" href="{{ route('website.home') }}">Nearby Teacher</a>
        <a class="text-link" href="{{ route('website.directory') }}">Explore teachers &amp; institutes →</a>
    </div></header>
    <main class="teacher-shell">
        <nav class="teacher-crumbs" aria-label="Breadcrumb">
            <a href="{{ route('website.directory') }}">Teachers</a><span aria-hidden="true">/</span>
            <a href="{{ $cityUrl }}">{{ $teacher->city }}</a><span aria-hidden="true">/</span>
            <span aria-current="page">{{ $teacher->full_name }}</span>
        </nav>
        <header class="teacher-hero">
            <div class="avatar" aria-hidden="true">
                @if ($teacher->avatar_url)<img src="{{ $teacher->avatar_url }}" alt="" width="96" height="96" decoding="async">
                @else{{ $initials }}@endif
            </div>
            <div>
                <p class="teacher-kicker">{{ $fullProfile ? 'Teacher profile' : 'Meet your next teacher' }}</p>
                <h1>@if ($fullProfile){{ $teacher->full_name }}@else<a href="{{ $profileUrl }}">{{ $teacher->full_name }}</a>@endif</h1>
                <p class="teacher-subtitle">{{ $subjectNames }} teacher in {{ $teacher->city }}</p>
                <p class="teacher-location">{{ $location }}</p>
            </div>
        </header>
        <div class="teacher-columns">
            <div>
                @if ($teacher->bio)
                    <section class="teacher-panel" aria-labelledby="about-heading">
                        <h2 id="about-heading">About {{ $teacher->full_name }}</h2>
                        <p class="bio">{{ $teacher->bio }}</p>
                    </section>
                @endif
                <section class="teacher-panel" aria-labelledby="subjects-heading">
                    <h2 id="subjects-heading">Subjects taught</h2>
                    <div class="tags">@foreach ($subjects as $subject)<span class="tag">{{ $subject->name }}</span>@endforeach</div>
                    @if ($modeNames)
                        <h2 class="mode-heading">How lessons work</h2>
                        <div class="tags">@foreach ($modes as $mode)@if (isset($labels[$mode]))<span class="tag">{{ $labels[$mode] }}</span>@endif @endforeach</div>
                    @endif
                    <div class="teacher-stats">
                        <div class="teacher-stat"><strong>{{ $teacher->lessons_completed }}</strong><span>Lessons completed</span></div>
                        <div class="teacher-stat">
                            @if ($teacher->rating_count > 0)<strong>★ {{ $teacher->rating_avg }}</strong><span>{{ $teacher->rating_count }} reviews</span>
                            @else<strong>—</strong><span>No reviews yet</span>@endif
                        </div>
                    </div>
                </section>
                @if ($fullProfile)
                    @if ($teacher->linkedin_url)
                        <section class="teacher-panel" aria-labelledby="links-heading">
                            <h2 id="links-heading">Professional links</h2>
                            <a class="text-link" href="{{ $teacher->linkedin_url }}" target="_blank" rel="noopener noreferrer nofollow ugc">View LinkedIn ↗</a>
                        </section>
                    @endif
                    <section class="teacher-panel" aria-labelledby="reviews-heading">
                        <h2 id="reviews-heading">Student reviews</h2>
                        @forelse ($reviews as $review)
                            <article class="teacher-review">
                                <span class="teacher-review-stars">★ {{ $review->rating }}/5</span>
                                <time class="meta" datetime="{{ \Illuminate\Support\Carbon::parse($review->created_at)->toDateString() }}"> · {{ \Illuminate\Support\Carbon::parse($review->created_at)->format('M j, Y') }}</time>
                                @if ($review->comment)<p>{{ $review->comment }}</p>@endif
                            </article>
                        @empty
                            <p class="teacher-note">No reviews yet.</p>
                        @endforelse
                        @if ($reviews->hasPages())
                            <nav class="teacher-pagination" aria-label="Review pages">
                                @if ($reviews->previousPageUrl())<a class="text-link" href="{{ $reviews->previousPageUrl() }}" rel="prev">Previous</a>@endif
                                @if ($reviews->nextPageUrl())<a class="text-link" href="{{ $reviews->nextPageUrl() }}" rel="next">Next</a>@endif
                            </nav>
                        @endif
                    </section>
                @else
                    <section class="teacher-panel">
                        <h2>Get to know {{ $teacher->full_name }}</h2>
                        <p class="teacher-note">Explore the full profile for available professional links and student reviews.</p>
                        <a class="text-link" href="{{ $profileUrl }}">View full profile →</a>
                    </section>
                @endif
            </div>
            <aside class="teacher-panel teacher-message" aria-labelledby="message-heading">
                <p class="teacher-kicker">Start a conversation</p>
                <h2 id="message-heading">Learn with {{ $teacher->full_name }}</h2>
                @if ($teacher->rate_per_hour !== null)
                    <p class="teacher-price">{{ (float) $teacher->rate_per_hour === 0.0 ? 'Free' : number_format((float) $teacher->rate_per_hour, 2).' '.$teacher->currency }}</p>
                    @if ((float) $teacher->rate_per_hour !== 0.0)<p class="meta">Per hour</p>@endif
                @endif
                @if ($teacher->trial_days > 0)<p class="teacher-trial">Trial days: {{ $teacher->trial_days }}</p>@endif
                <p class="teacher-note">Ask about lessons, subjects and availability directly through the app.</p>
                <a class="btn" href="{{ $downloadHref }}">Download the app to message</a>
                @if (!$fullProfile)<a class="btn teacher-secondary" href="{{ $profileUrl }}">View full profile</a>@endif
                <p class="meta">Send messages through the app.</p>
            </aside>
        </div>
        <footer class="teacher-bottom">Looking for more options? <a class="text-link" href="{{ $cityUrl }}">Explore teachers in {{ $teacher->city }}</a>.</footer>
    </main>
</div>
@endsection
