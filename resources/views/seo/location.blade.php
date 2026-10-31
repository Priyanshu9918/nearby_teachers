@extends('site.layout')
@section('documentLanguage', $language)
@section('documentDirection', $direction)
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
        <nav class="teacher-crumbs" aria-label="Breadcrumb">
            <a href="{{ \App\Services\SeoDirectory::url('/directory') }}">Home</a>
            <span aria-hidden="true">›</span><a href="{{ \App\Services\SeoDirectory::url('/'.$page->country) }}">{{ config('seo.countries.'.$page->country.'.name') }}</a>
            @foreach ($breadcrumbs as $crumb)<span aria-hidden="true">›</span>@if ($crumb['url'])<a href="{{ $crumb['url'] }}">{{ $crumb['label'] }}</a>@else<span aria-current="page">{{ $crumb['label'] }}</span>@endif @endforeach
        </nav>

        <header class="directory-heading">
            <h1>{{ $title }}</h1>
            <p>{{ \App\Services\SeoDirectory::text($labels, 'count', ['count' => $providers->total()]) }} <span aria-hidden="true">·</span> {{ $description }}</p>
        </header>

        <form id="directory-filter-form" method="get" action="{{ request()->url() }}" class="directory-search" role="search">
            @if($language !== \App\Services\SeoDirectory::language($page->country))<input type="hidden" name="lang" value="{{ $language }}">@endif
            <label class="sr-only" for="teacher-search">Search teachers, institutes and subjects</label>
            <input id="teacher-search" name="q" type="search" maxlength="100" value="{{ $searchTerm }}" placeholder="What to learn? Maths, IELTS, Guitar…" autocomplete="off">
            <label class="sr-only" for="teacher-mode">Lesson mode</label>
            <select id="teacher-mode" name="mode" aria-label="Lesson mode">
                <option value="">All lesson modes</option>
                <option value="home_tuition" @selected(($filters['mode'] ?? '') === 'home_tuition')>Home tuition</option>
                <option value="online" @selected(($filters['mode'] ?? '') === 'online')>Online</option>
                <option value="in_person" @selected(($filters['mode'] ?? '') === 'in_person')>At the centre</option>
            </select>
            <label class="directory-trial"><input id="teacher-trial" type="checkbox" name="trial" value="1" @checked($trialOnly)> Free trial only</label>
            <button type="submit">Apply filters</button>
            @if($hasActiveFilters)<a class="directory-clear" href="{{ $clearFiltersUrl }}">Clear filters</a>@endif
        </form>

        @if($hasActiveFilters)
            <div class="directory-active-filters" aria-label="Active filters">
                <strong>Showing {{ $providers->total() }} matching profiles</strong>
                @if($searchTerm !== '')<span>Search: “{{ $searchTerm }}”</span>@endif
                @if(isset($filters['mode']))<span>{{ $labels[$filters['mode']] ?? $filters['mode'] }}</span>@endif
                @if($trialOnly)<span>Free trial only</span>@endif
            </div>
        @endif

        <script>
            (() => {
                const form = document.getElementById('directory-filter-form');
                if (!form) return;
                form.querySelectorAll('select[name="mode"], input[name="trial"]').forEach((control) => {
                    control.addEventListener('change', () => form.requestSubmit());
                });
            })();
        </script>

        @if ($children)
            <nav class="directory-browse" aria-label="{{ $labels['browse'] ?? 'Browse locations and subjects' }}">
                @foreach ($children as $child)<a href="{{ $child->url }}">{{ $child->label }}</a>@endforeach
            </nav>
        @endif

        <section class="directory-results" aria-label="{{ $title }}">
            @forelse ($providers as $item)
                @php($profileUrl = \App\Services\SeoDirectory::url('/'.$item->provider_type.'/'.$item->public_page_slug))
                <article class="directory-card">
                    <div class="directory-card-heading">
                        <div class="directory-avatar" aria-hidden="true">@if ($item->avatar_url)<img src="{{ \App\Support\PublicMediaUrl::resolve($item->avatar_url) }}" alt="" loading="lazy" width="48" height="48">@else{{ mb_substr($item->full_name, 0, 1) }}@endif</div>
                        <div><h2><a href="{{ $profileUrl }}">{{ $item->full_name }}</a></h2><span class="directory-points">{{ $item->provider_type === 'institute' ? 'Institute' : 'Teacher' }}@if($item->provider_type === 'teacher') · Referral points: {{ $item->referral_points }}@endif</span></div>
                    </div>
                    <p class="directory-place">
                        @if ($item->rating_count > 0)★ {{ number_format((float) $item->rating_avg, 1) }} ({{ $item->rating_count }}) <span>·</span> @else{{ $labels['no_reviews'] }} <span>·</span> @endif
                        {{ collect([$item->locality, $item->district, $item->city])->filter()->unique()->implode(', ') }}
                    </p>
                    @if($item->subject_names)<p class="directory-subjects">{{ $item->subject_names }}</p>@endif
                    <div class="directory-tags">@foreach (json_decode($item->lesson_modes ?: '[]', true) as $mode)@if(isset($labels[$mode]))<span>{{ $labels[$mode] }}</span>@endif @endforeach @if($item->trial_days > 0)<span class="directory-free-trial">Free trial · {{ $item->trial_days }} {{ (int) $item->trial_days === 1 ? 'day' : 'days' }}</span>@endif</div>
                    @if ($item->rate !== null)<p class="directory-rate">Fees from {{ number_format((float) $item->rate, 0) }} {{ $item->currency }}/hour</p>@endif
                    <div class="directory-actions"><a class="directory-contact" href="{{ route('download', ['type' => $item->provider_type, 'slug' => $item->public_page_slug]) }}">Get the app to contact</a><a class="directory-profile" href="{{ $profileUrl }}">View profile</a></div>
                </article>
            @empty
                <p class="directory-empty">No teachers or institutes match these filters. Try clearing a filter or changing your search.</p>
            @endforelse
        </section>
        <nav class="seo-pages directory-pagination" aria-label="Pagination">@if ($providers->previousPageUrl())<a href="{{ $providers->previousPageUrl() }}" rel="prev">{{ $labels['previous'] }}</a>@endif @if ($providers->nextPageUrl())<a href="{{ $providers->nextPageUrl() }}" rel="next">{{ $labels['next'] }}</a>@endif</nav>
    </main>
</div>
@endsection
