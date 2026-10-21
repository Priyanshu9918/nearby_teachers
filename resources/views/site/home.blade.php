@extends('site.layout')

@section('title', data_get($t, 'common.appName', 'Nearby Teacher'))

@section('content')
<main class="wrap">
    <h1>{{ data_get($t, 'common.appName', 'Nearby Teacher') }}</h1>
    <p class="sub">{{ data_get($t, 'website.findNearYou') }}</p>
    <a class="btn" href="{{ route('website.directory') }}">{{ data_get($t, 'website.cityDirectory') }}</a>
    <a class="btn dark" href="{{ route('website.location-lookup') }}">Look up a location by coordinates</a>
</main>
@endsection
