@extends('site.layout')

@section('title', str_replace('{city}', $city, data_get($t, 'website.teachersAndInstitutesIn')))
@section('description', str_replace('{count}', (string) $subjects->count(), data_get($t, 'website.subjectsCovered')))

@section('content')
<main class="wrap">
    <h1>{{ str_replace('{city}', $city, data_get($t, 'website.teachersAndInstitutesIn')) }}</h1>
    <p class="sub">
        {{ str_replace('{count}', (string) ($teachers->count() + $institutes->count()), data_get($t, 'website.listingsCount')) }}
        · {{ str_replace('{count}', (string) $subjects->count(), data_get($t, 'website.subjectsCovered')) }}
    </p>
    @if ($subjects->isNotEmpty())
        <h2>{{ str_replace('{city}', $city, data_get($t, 'website.subjectsTaughtIn')) }}</h2>
        <div class="tags">@foreach ($subjects as $subject)<span class="tag">{{ $subject->name }}</span>@endforeach</div>
    @endif

    <h2>{{ data_get($t, 'website.institutesHeading') }}</h2>
    @if ($institutes->isEmpty())
        <p class="empty">{{ str_replace('{city}', $city, data_get($t, 'website.noInstitutesIn')) }}</p>
    @else
        <div class="list">
            @foreach ($institutes as $institute)
                <a class="row" href="{{ route('website.institute', ['slug' => $institute->slug]) }}">
                    <div><p class="name">{{ $institute->full_name }}</p><p class="meta">
                        @if ($institute->rating_count > 0)★ {{ $institute->rating_avg }} ({{ $institute->rating_count }})
                        @else{{ data_get($t, 'website.noReviewsYet') }}@endif
                    </p></div>
                    @if ($institute->is_bsl_official)<span class="badge bsl">{{ data_get($t, 'common.verified') }}</span>@endif
                </a>
            @endforeach
        </div>
    @endif

    <h2>{{ data_get($t, 'website.teachersHeading') }}</h2>
    @if ($teachers->isEmpty())
        <p class="empty">{{ str_replace('{city}', $city, data_get($t, 'website.noTeachersIn')) }}</p>
    @else
        <div class="list">
            @foreach ($teachers as $teacher)
                <a class="row" href="{{ route('website.teacher', ['slug' => $teacher->slug]) }}">
                    <div><p class="name">{{ $teacher->full_name }}</p><p class="meta">
                        @if ($teacher->rating_count > 0)★ {{ $teacher->rating_avg }} ({{ $teacher->rating_count }})
                        @else{{ data_get($t, 'website.noReviewsYet') }}@endif
                    </p></div>
                </a>
            @endforeach
        </div>
    @endif
    <p><a class="btn" href="{{ route('website.feed', ['city' => $city]) }}">{{ str_replace('{city}', $city, data_get($t, 'website.seeWhatsHappening')) }} →</a></p>
</main>
@endsection
