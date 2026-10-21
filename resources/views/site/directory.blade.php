@extends('site.layout')

@section('title', data_get($t, 'website.findNearYou'))
@section('description', str_replace('{count}', (string) $cities->count(), data_get($t, 'website.citiesOnPlatform')))

@section('content')
<main class="wrap">
    <h1>{{ data_get($t, 'website.findNearYou') }}</h1>
    <p class="sub">{{ str_replace('{count}', (string) $cities->count(), data_get($t, 'website.citiesOnPlatform')) }}</p>
    @if ($cities->isEmpty())
        <p class="empty">{{ data_get($t, 'website.noCitiesYet') }}</p>
    @else
        <div class="grid">
            @foreach ($cities as $entry)
                <a class="city-card" href="{{ route('website.city', ['city' => $entry->city]) }}">
                    <div><p class="name">{{ $entry->city }}</p>
                        <p class="count">{{ str_replace('{count}', (string) $entry->listing_count, data_get($t, 'website.listingsCount')) }}</p>
                    </div>
                </a>
            @endforeach
        </div>
    @endif
</main>
@endsection
