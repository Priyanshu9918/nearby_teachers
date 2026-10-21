@extends('site.layout')

@section('title', data_get($t, 'website.notFound'))

@section('content')
<main class="center"><div class="box">
    <h1>{{ data_get($t, 'website.notFound') }}</h1>
    <p class="sub">{{ $message ?? data_get($t, 'website.notFound') }}</p>
    <a class="text-link" href="{{ route('website.home') }}">{{ data_get($t, 'common.appName') }}</a>
</div></main>
@endsection
