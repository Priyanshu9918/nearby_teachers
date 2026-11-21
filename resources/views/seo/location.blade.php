@extends('site.layout')
@section('languagePickerInHeader', '1')
@section('documentLanguage', $language)
@section('documentDirection', $direction)
@section('title', $title)
@section('description', $description)
@php
    $countryLanding = $page->kind === 'country';
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
@section('meta')
    @include('seo.meta')
    @if($countryLanding)
        <style>
            .country-landing{background:#f5f8fc}
            .country-landing .directory-shell{max-width:1180px;padding:2rem 1.5rem 5rem}
            .country-landing .teacher-crumbs{margin-bottom:1.25rem;font-size:.875rem}
            .country-hero{position:relative;isolation:isolate;display:grid;grid-template-columns:minmax(0,1.1fr) minmax(340px,.9fr);align-items:center;gap:2rem;overflow:hidden;padding:clamp(2rem,5vw,4rem);border-radius:2rem;background:linear-gradient(120deg,#102b4e 0%,#153b63 58%,#14514e 115%);box-shadow:0 24px 60px #102b4e20;color:#fff}
            .country-hero:before,.country-hero:after{position:absolute;z-index:-1;content:"";pointer-events:none}
            .country-hero:before{width:28rem;height:28rem;inset-inline-end:-9rem;top:-15rem;border:1px solid #ffffff14;border-radius:50%;box-shadow:0 0 0 3rem #ffffff08,0 0 0 6rem #ffffff05}
            .country-hero:after{width:18rem;height:18rem;inset-inline-start:38%;bottom:-15rem;border-radius:50%;background:#e7a54a28;filter:blur(18px)}
            .country-hero-copy{max-width:40rem}
            .country-kicker{display:inline-flex;align-items:center;gap:.65rem;margin:0 0 1.25rem;color:#eaf2ff;font-size:.875rem;font-weight:700;letter-spacing:.04em}
            .country-kicker:before{width:2rem;height:.22rem;border-radius:1rem;background:linear-gradient(90deg,#f0a247 0 50%,#58b78c 50%);content:""}
            .country-hero h1{max-width:15ch;margin:0;color:#fff;font-size:clamp(2.4rem,5vw,4.2rem);line-height:1.03;letter-spacing:-.045em}
            .country-hero-description{max-width:38rem;margin:1.25rem 0 1.5rem;color:#d5e2f1;font-size:1.125rem;line-height:1.65}
            .country-profile-count{display:inline-flex;align-items:center;gap:.85rem;padding:.8rem 1rem;border:1px solid #ffffff30;border-radius:1rem;background:#ffffff0d;color:#fff}
            .country-profile-count strong{font-size:1.45rem;line-height:1}
            .country-profile-count span{font-size:.9rem;color:#d5e2f1}
            .country-search-panel{padding:1.5rem;border:1px solid #ffffff65;border-radius:1.5rem;background:#fff;color:#102b4e;box-shadow:0 20px 45px #07192c35}
            .country-search-panel h2{margin:0 0 1.15rem;font-size:1.25rem;line-height:1.35}
            .country-search-form{display:grid;gap:.85rem}
            .country-search-form input[type=search],.country-search-form select{width:100%;min-height:3.1rem;border:1px solid #dbe3ed;border-radius:.85rem;background:#fff;padding:.75rem .9rem;color:#102b4e;font:inherit;font-size:1rem}
            .country-search-form select{appearance:none;padding-inline-end:2.5rem;background-image:linear-gradient(45deg,transparent 50%,#53677e 50%),linear-gradient(135deg,#53677e 50%,transparent 50%);background-position:calc(100% - 1.1rem) 1.35rem,calc(100% - .8rem) 1.35rem;background-size:.35rem .35rem,.35rem .35rem;background-repeat:no-repeat}
            [dir=rtl] .country-search-form select{background-position:.8rem 1.35rem,1.1rem 1.35rem}
            .country-search-form input:focus-visible,.country-search-form select:focus-visible,.country-search-form button:focus-visible,.country-search-form a:focus-visible{outline:3px solid #f0a247;outline-offset:2px}
            .country-search-options{display:grid;grid-template-columns:minmax(0,1fr) auto;align-items:center;gap:.75rem}
            .country-search-trial{display:flex;align-items:center;gap:.55rem;min-height:3rem;padding:.65rem .75rem;border:1px solid #dbe3ed;border-radius:.85rem;color:#34465b;font-size:.9rem}
            .country-search-trial input{width:1.1rem;height:1.1rem;accent-color:#176b58}
            .country-search-form button{min-height:3.1rem;border:0;border-radius:.85rem;background:#d88a32;padding:.75rem 1rem;color:#fff;font:inherit;font-size:1rem;font-weight:750;cursor:pointer;transition:background .15s ease,transform .15s ease}
            .country-search-form button:hover{background:#bf7121;transform:translateY(-1px)}
            .country-search-form .directory-clear{font-size:.9rem}
            .country-landing .directory-active-filters{margin:1rem 0 0;font-size:.875rem}
            .country-section{margin-top:3.5rem}
            .country-section-heading{display:flex;align-items:end;justify-content:space-between;gap:1rem;margin-bottom:1.15rem}
            .country-section-heading h2{margin:0;color:#102b4e;font-size:clamp(1.5rem,3vw,2.1rem);letter-spacing:-.025em}
            .country-section-heading p{margin:0;color:#66768a;font-size:.95rem}
            .country-area-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:.85rem}
            .country-area-card{display:flex;min-height:6.75rem;align-items:center;justify-content:space-between;gap:.75rem;padding:1rem 1.05rem;border:1px solid #e0e8f1;border-radius:1rem;background:#fff;color:#102b4e;text-decoration:none;transition:border-color .15s ease,box-shadow .15s ease,transform .15s ease}
            .country-area-card:hover{transform:translateY(-2px);border-color:#89a9c8;box-shadow:0 12px 28px #102b4e12}
            .country-area-card:focus-visible{outline:3px solid #2f80ff;outline-offset:3px}
            .country-area-name{font-size:1rem;font-weight:700;line-height:1.35}
            .country-area-count{flex:0 0 auto;color:#176b58;text-align:end}
            .country-area-count strong{display:block;font-size:1.15rem;line-height:1.2}
            .country-area-count span{font-size:.8rem;color:#66768a}
            .country-results-section{margin-top:3.5rem}
            .country-results-heading{display:flex;align-items:end;justify-content:space-between;gap:1rem;margin-bottom:1rem}
            .country-results-heading h2{margin:0;color:#102b4e;font-size:clamp(1.5rem,3vw,2.1rem);letter-spacing:-.025em}
            .country-results-heading p{margin:0;color:#66768a;font-size:.95rem}
            .country-landing .directory-results{grid-template-columns:repeat(2,minmax(0,1fr));gap:1rem;margin-top:0}
            .country-landing .directory-card{padding:1.25rem;border-radius:1.25rem;box-shadow:0 8px 24px #102b4e08}
            .country-landing .directory-card h2{font-size:1.15rem}
            .country-landing .directory-place,.country-landing .directory-subjects,.country-landing .directory-rate{font-size:.95rem;line-height:1.55}
            .country-landing .directory-tags span{font-size:.85rem}
            .country-landing .directory-actions a{min-height:2.9rem;padding:.75rem;font-size:.9rem}
            .country-landing .directory-pagination{margin-top:1.5rem}
            @media(max-width:900px){.country-hero{grid-template-columns:1fr}.country-hero-copy{max-width:48rem}.country-search-panel{max-width:42rem}.country-area-grid{grid-template-columns:repeat(3,minmax(0,1fr))}}
            @media(max-width:640px){.country-landing .directory-shell{padding:1rem 1rem 3rem}.country-hero{gap:1.5rem;padding:1.5rem;border-radius:1.4rem}.country-hero h1{font-size:2.5rem}.country-hero-description{font-size:1rem}.country-search-panel{padding:1.1rem;border-radius:1.1rem}.country-search-options{grid-template-columns:1fr}.country-area-grid{grid-template-columns:repeat(2,minmax(0,1fr));gap:.6rem}.country-area-card{min-height:6rem;padding:.8rem}.country-area-name{font-size:.95rem}.country-section,.country-results-section{margin-top:2.5rem}.country-section-heading,.country-results-heading{align-items:flex-start;flex-direction:column}.country-landing .directory-results{grid-template-columns:1fr}}
            @media(max-width:380px){.country-area-grid{grid-template-columns:1fr}.country-hero h1{font-size:2.1rem}}
            @media(prefers-reduced-motion:reduce){.country-area-card,.country-search-form button{transition:none}}
        </style>
    @endif
@endsection
@section('content')
<div class="teacher-page directory-page {{ $countryLanding ? 'country-landing' : '' }}">
    @include('seo.partials.header', ['headerCountry' => $page->country])
    <main class="directory-shell">
        <nav class="teacher-crumbs" aria-label="Breadcrumb">
            <a href="{{ \App\Services\SeoDirectory::url('/directory') }}">{{ $labels['home'] }}</a>
            @foreach ($breadcrumbs as $crumb)<span aria-hidden="true">›</span>@if($page->kind === 'country' && $loop->first)<span aria-current="page">{{ $crumb['label'] }}</span>@elseif ($crumb['url'])<a href="{{ $crumb['url'] }}">{{ $crumb['label'] }}</a>@else<span aria-current="page">{{ $crumb['label'] }}</span>@endif @endforeach
        </nav>

        @if($countryLanding)
            <section class="country-hero" aria-labelledby="country-page-title">
                <div class="country-hero-copy">
                    <p class="country-kicker">{{ $labels['directory'] }}</p>
                    <h1 id="country-page-title">{{ $title }}</h1>
                    <p class="country-hero-description">{{ $description }}</p>
                    <div class="country-profile-count"><strong>{{ number_format($providers->total()) }}</strong><span>{{ $labels['teachers'] }}</span></div>
                </div>
                <div class="country-search-panel">
                    <h2>{{ $labels['search_label'] }}</h2>
                    @include('seo.partials.location-search')
                </div>
            </section>
        @else
            <header class="directory-heading">
                <h1>{{ $title }}</h1>
                <p>{{ \App\Services\SeoDirectory::text($labels, 'count', ['count' => $providers->total()]) }} <span aria-hidden="true">·</span> {{ $description }}</p>
            </header>
            @include('seo.partials.location-search')
        @endif

        @if($hasActiveFilters)
            <div class="directory-active-filters" aria-label="Active filters">
                <strong>{{ \App\Services\SeoDirectory::text($labels, 'matching', ['count' => $providers->total()]) }}</strong>
                @if($searchTerm !== '')<span>{{ $labels['search'] }}: “{{ $searchTerm }}”</span>@endif
                @if(isset($filters['mode']))<span>{{ $labels[$filters['mode']] ?? $filters['mode'] }}</span>@endif
                @if($trialOnly)<span>{{ $labels['trial_only'] }}</span>@endif
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
            @if($countryLanding)
                <section class="country-section" aria-labelledby="country-areas-title">
                    <div class="country-section-heading">
                        <h2 id="country-areas-title">{{ $labels['cities'] }}</h2>
                        <p>{{ $labels['more'] }}</p>
                    </div>
                    <nav class="country-area-grid" aria-label="{{ $labels['cities'] }}">
                        @foreach (collect($children)->sortByDesc('eligible_providers') as $child)
                            <a class="country-area-card" href="{{ $child->url }}">
                                <span class="country-area-name">{{ $child->label }}</span>
                                <span class="country-area-count"><strong>{{ number_format((int) $child->eligible_providers) }}</strong><span>{{ $labels['teachers'] }}</span></span>
                            </a>
                        @endforeach
                    </nav>
                </section>
            @else
                <nav class="directory-browse" aria-label="{{ $labels['browse'] ?? 'Browse locations and subjects' }}">
                    @foreach ($children as $child)<a href="{{ $child->url }}">{{ $child->label }}</a>@endforeach
                </nav>
            @endif
        @endif

        @if($countryLanding)
            <section class="country-results-section" aria-labelledby="country-results-title">
                <div class="country-results-heading">
                    <h2 id="country-results-title">{{ $labels['teachers'] }}</h2>
                    <p>{{ \App\Services\SeoDirectory::text($labels, 'count', ['count' => $providers->total()]) }}</p>
                </div>
        @endif
        <section class="directory-results {{ $countryLanding ? 'country-provider-grid' : '' }}" aria-label="{{ $title }}">
            @forelse ($providers as $item)
                @php($profileUrl = \App\Services\SeoDirectory::url('/'.$item->provider_type.'/'.$item->public_page_slug))
                <article class="directory-card">
                    <div class="directory-card-heading">
                        <div class="directory-avatar" aria-hidden="true">@if ($item->avatar_url)<img src="{{ \App\Support\PublicMediaUrl::resolve($item->avatar_url) }}" alt="" loading="lazy" width="48" height="48">@else{{ mb_substr($item->full_name, 0, 1) }}@endif</div>
                        <div><h2><a href="{{ $profileUrl }}">{{ $item->full_name }}</a></h2><span class="directory-points">{{ $item->provider_type === 'institute' ? $labels['institute'] : $labels['teacher'] }}@if($item->provider_type === 'teacher') · {{ $labels['referral_points'] }}: {{ $item->referral_points }}@endif</span></div>
                    </div>
                    <p class="directory-place">
                        {{-- Student review ratings temporarily hidden. @if ($item->rating_count > 0)★ {{ number_format((float) $item->rating_avg, 1) }} ({{ $item->rating_count }}) <span>·</span> @else{{ $labels['no_reviews'] }} <span>·</span> @endif --}}
                        {{ \App\Support\SeoProfileDetails::address($item) }}
                    </p>
                    @if($item->subject_names)<p class="directory-subjects">{{ $item->subject_names }}</p>@endif
                    <div class="directory-tags">@foreach (json_decode($item->lesson_modes ?: '[]', true) as $mode)@if(isset($labels[$mode]))<span>{{ $labels[$mode] }}</span>@endif @endforeach @if($item->trial_days > 0)<span class="directory-free-trial">{{ \App\Services\SeoDirectory::text($labels, (int) $item->trial_days === 1 ? 'trial_duration_one' : 'trial_duration', ['days' => (int) $item->trial_days]) }}</span>@endif</div>
                    @if ($item->rate !== null)<p class="directory-rate">{{ \App\Services\SeoDirectory::text($labels, 'fees_from', ['price' => number_format((float) $item->rate, 0), 'currency' => $item->currency]) }}</p>@endif
                    @include('seo.partials.profile-details', ['item' => $item, 'contactHref' => route('download', ['type' => $item->provider_type, 'slug' => $item->public_page_slug])])
                    <div class="directory-actions"><a class="directory-contact" href="{{ route('download', ['type' => $item->provider_type, 'slug' => $item->public_page_slug]) }}">{{ $labels['download_contact'] }}</a><a class="directory-profile" href="{{ $profileUrl }}">{{ $labels['profile'] }}</a></div>
                </article>
            @empty
                <p class="directory-empty">{{ $labels['empty'] }}</p>
            @endforelse
        </section>
        @if($countryLanding)
            </section>
        @endif
        <nav class="seo-pages directory-pagination" aria-label="Pagination">@if ($providers->previousPageUrl())<a href="{{ $providers->previousPageUrl() }}" rel="prev">{{ $labels['previous'] }}</a>@endif @if ($providers->nextPageUrl())<a href="{{ $providers->nextPageUrl() }}" rel="next">{{ $labels['next'] }}</a>@endif</nav>
    </main>
</div>
@endsection
