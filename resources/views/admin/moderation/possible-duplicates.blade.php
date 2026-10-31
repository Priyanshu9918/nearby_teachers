@extends('admin.layout')
@section('title', 'Possible teacher duplicates')
@section('breadcrumb', 'Possible duplicates')
@section('content')
<div class="page-heading">
    <div><p class="eyebrow">Review &amp; protect</p><h1>Possible duplicate teachers</h1><p>These profiles share a name and city. They may be different people, so review each record before taking action.</p></div>
    <span class="count-pill">{{ number_format($matches->total()) }} possible matches</span>
</div>
<section class="panel">
    <div class="panel-head"><div><h2>Profiles to compare</h2><p>No records are merged or removed from this screen.</p></div><a class="btn btn-small" href="{{ route('admin.index', ['section' => 'teachers']) }}">Manage teachers <x-admin-icon name="arrow" /></a></div>
    @if ($matches->count())
        <div class="table-wrap" tabindex="0" role="region" aria-label="Possible duplicate teacher profiles">
            <table class="data-table"><thead><tr><th scope="col">Teacher</th><th scope="col">Phone</th><th scope="col">City</th><th scope="col">Joined</th><th scope="col">Review</th></tr></thead><tbody>
            @foreach ($matches as $match)
                <tr><td><strong class="record-name">{{ $match->full_name }}</strong><span class="record-sub">ID {{ $match->id }}</span></td><td>{{ $match->phone ?: 'Not provided' }}</td><td>{{ collect([$match->city, $match->country])->filter()->join(', ') }}</td><td>{{ $match->created_at ? \Illuminate\Support\Carbon::parse($match->created_at)->format('M j, Y') : '—' }}</td><td><a class="btn btn-small" href="{{ route('admin.reports.user', $match->id) }}">Open user report</a></td></tr>
            @endforeach
            </tbody></table>
        </div>
        @include('admin.partials.pagination', ['page' => $matches])
    @else
        <div class="empty-state"><span class="empty-icon"><x-admin-icon name="check" /></span><strong>No possible matches found</strong><p>Profiles with the same name and city will appear here for manual review.</p></div>
    @endif
</section>
@endsection
