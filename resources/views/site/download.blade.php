@extends('site.layout')

@section('title', data_get($t, 'website.downloadPrompt'))

@section('content')
<main class="center">
    <div class="box">
        <h1>{{ data_get($t, 'common.appName') }}</h1>
        <p class="sub">{{ data_get($t, 'website.downloadPrompt') }}</p>
        <div class="links">
            <a class="btn dark {{ $iosUrl ? '' : 'disabled' }}" href="{{ $iosUrl ?: '#' }}">{{ data_get($t, 'common.download') }} — iOS</a>
            <a class="btn {{ $androidUrl ? '' : 'disabled' }}" href="{{ $androidUrl ?: '#' }}">{{ data_get($t, 'common.download') }} — Android</a>
        </div>
        <p class="meta">{{ data_get($t, 'website.openingApp') }}</p>
        <p class="meta">{{ $deepLinkPath }} @if ($ref) · {{ $ref }} @endif</p>

    </div>
</main>
@endsection
