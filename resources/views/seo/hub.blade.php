@extends('site.layout')
@section('documentLanguage', 'en')
@section('documentDirection', 'ltr')
@section('title', 'Teacher directory')
@section('meta')<link rel="canonical" href="{{ \App\Services\SeoDirectory::url('/directory') }}">@endsection
@section('content')<main class="wrap"><h1>Find a teacher</h1><p class="sub">Browse available subjects by country and city, or explore online lessons.</p><div class="list">@forelse ($pages as $page)<a class="row" href="{{ \App\Services\SeoDirectory::url($page->path) }}"><span>{{ $page->display_name }} · {{ $page->kind === 'online' ? 'Online' : $page->city }} · {{ strtoupper($page->country) }}</span><span>{{ strtoupper($page->language) }} →</span></a>@empty<p>No published listings yet.</p>@endforelse</div><p>@if ($pages->previousPageUrl())<a href="{{ $pages->previousPageUrl() }}">Previous</a>@endif @if ($pages->nextPageUrl())<a href="{{ $pages->nextPageUrl() }}">Next</a>@endif</p></main>@endsection
