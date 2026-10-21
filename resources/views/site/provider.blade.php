@extends('site.layout')

@php
    $isInstitute = $providerType === 'institute';
    $rate = $isInstitute ? $provider->starting_rate : $provider->rate_per_hour;
    $description = $isInstitute ? $provider->description : $provider->bio;
    $initials = collect(preg_split('/\s+/', trim($provider->full_name)) ?: [])->filter()->take(2)->map(fn ($word) => mb_substr($word, 0, 1))->implode('');
    $city = $provider->city ?: '';
@endphp

@section('title', $provider->full_name.' — '.($provider->city ?: data_get($t, 'common.appName')))
@section('description', mb_substr($description ?: ($provider->full_name.' — '.data_get($t, 'common.appName')), 0, 155))
@section('meta')
    <meta property="og:title" content="{{ $provider->full_name }}">
    <meta property="og:description" content="{{ $description ?? '' }}">
    <meta property="og:type" content="{{ $isInstitute ? 'business.business' : 'profile' }}">
@endsection

@section('content')
<main class="wrap">
    <article class="card profile-card {{ $isInstitute ? 'institute' : '' }}">
        <div class="avatar">
            @if ($provider->avatar_url)<img src="{{ $provider->avatar_url }}" alt="{{ $provider->full_name }}">
            @else{{ $initials }}@endif
        </div>
        <h1>{{ $provider->full_name }}</h1>
        <p class="meta">{{ collect([$provider->city, $provider->country])->filter()->implode(', ') }}
            @if ($provider->rating_count > 0) · ★ {{ $provider->rating_avg }} ({{ $provider->rating_count }})@endif
        </p>
        @if ($isInstitute && $provider->is_bsl_official)
            <span class="badge bsl">{{ data_get($t, 'common.verified') }}
                @if ($provider->bsl_official_since)
                    {{ str_replace('{date}', \Illuminate\Support\Carbon::parse($provider->bsl_official_since)->locale($lang)->translatedFormat('M j, Y'), data_get($t, 'website.since')) }}
                @endif
            </span>
        @endif
        @if ($provider->trial_days > 0)
            <span class="badge trial">{{ $provider->trial_days === 7 ? data_get($t, 'profile.trialWeek') : data_get($t, 'profile.trial1Day') }}</span>
        @endif
        @if ($rate !== null && (float) $rate === 0.0)<span class="badge free">{{ data_get($t, 'common.free') }}</span>@endif

        @if ($isInstitute && $provider->website_url)
            <p><a class="text-link" href="{{ $provider->website_url }}">{{ data_get($t, 'profile.website') }}</a></p>
        @elseif (!$isInstitute && $provider->linkedin_url)
            <p><a class="text-link" href="{{ $provider->linkedin_url }}">{{ data_get($t, 'profile.linkedIn') }}</a></p>
        @endif
        @if ($description)<p class="bio">{{ $description }}</p>@endif
        @if ($subjects->isNotEmpty())
            <div class="tags">@foreach ($subjects as $subject)<span class="tag">{{ $subject->name }}</span>@endforeach</div>
        @endif
        <p class="meta">
            {{ str_replace('{count}', (string) $provider->lessons_completed, data_get($t, 'search.lessonsCompleted')) }}
            @if ($rate !== null)
                · {{ (float) $rate === 0.0 ? data_get($t, 'common.free') : str_replace('{price}', number_format((float) $rate, 2).' '.($provider->currency ?: 'INR'), data_get($t, $isInstitute ? 'website.fromPriceHour' : 'website.priceHour')) }}
            @endif
        </p>
        <div class="contact"><a class="btn" href="{{ $downloadHref }}">{{ data_get($t, 'website.downloadToMessage') }}</a></div>
        <p class="meta">{{ data_get($t, 'website.contactDetailsInApp') }}</p>
    </article>
    <p class="footer-note">
        {{ data_get($t, 'website.poweredBy') }} <a href="{{ route('website.directory') }}">{{ data_get($t, 'common.appName') }}</a>
        · <a href="{{ route('website.city', ['city' => $city]) }}">{{ str_replace('{city}', $city, data_get($t, 'website.allTeachersInstitutesIn')) }}</a>
        · <a href="{{ route('website.feed', ['city' => $city]) }}">{{ data_get($t, 'feed.title') }}</a>
    </p>
</main>
@endsection
