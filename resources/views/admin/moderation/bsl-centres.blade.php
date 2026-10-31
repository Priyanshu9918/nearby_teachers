@extends('admin.layout')
@section('title', 'BSL Verified centres')
@section('breadcrumb', 'BSL Verified')
@section('content')
<div class="page-heading">
    <div><p class="eyebrow">Review &amp; protect</p><h1>BSL Verified centres</h1><p>Give the badge only to directly operated BSL centres. It confirms BSL operation; it is not a safety or credential check.</p></div>
    <span class="count-pill">{{ number_format($centres->total()) }} centres</span>
</div>
<form method="get" class="panel panel-body queue-filter" role="search">
    <label for="centre-search">Find a centre</label><div><input id="centre-search" name="q" type="search" value="{{ $filters['q'] ?? '' }}" placeholder="Name or city"><button class="btn btn-primary" type="submit"><x-admin-icon name="search" /> Search</button>@if (!empty($filters['q']))<a class="btn" href="{{ route('admin.moderation.bsl') }}">Clear</a>@endif</div>
</form>
<section class="panel" aria-labelledby="bsl-centres-heading">
    <div class="panel-head"><div><h2 id="bsl-centres-heading">Centre eligibility</h2><p>Franchise and MCP partners are not eligible for the BSL Verified badge.</p></div><a class="btn btn-small" href="{{ route('admin.reports.index', ['report' => 'institute-profiles']) }}">All institute profiles <x-admin-icon name="arrow" /></a></div>
    @if ($centres->count())
        <div class="table-wrap" tabindex="0" role="region" aria-label="BSL centre eligibility list">
            <table class="data-table"><thead><tr><th scope="col">Centre</th><th scope="col">Location</th><th scope="col">Profile type</th><th scope="col">Badge</th><th scope="col">Action</th></tr></thead><tbody>
            @foreach ($centres as $centre)
                <tr>
                    <td><strong class="record-name">{{ $centre->full_name }}</strong><span class="record-sub">User ID {{ $centre->user_id }} · Profile {{ $centre->id }}</span></td>
                    <td>{{ collect([$centre->city, $centre->country])->filter()->join(', ') ?: 'Location not set' }}</td>
                    <td>{{ $centre->institute_type === 'bsl_direct' ? 'Direct BSL centre' : ucfirst(str_replace('_', ' ', $centre->institute_type ?? 'Not classified')) }}</td>
                    <td>@if ($centre->is_bsl_official)<span class="badge badge-bsl">BSL Verified</span>@elseif($centre->institute_type === 'bsl_direct')<span class="status-pill">Eligible</span>@else<span class="status-pill status-muted">Not eligible</span>@endif</td>
                    <td>
                        @if ($centre->is_bsl_official || $centre->institute_type === 'bsl_direct')
                            <form method="post" action="{{ route('admin.moderation.bsl.update', ['userId' => $centre->user_id]) }}">@csrf @method('patch')<input type="hidden" name="verified" value="{{ $centre->is_bsl_official ? '0' : '1' }}"><button class="btn btn-small {{ $centre->is_bsl_official ? 'btn-danger' : 'btn-primary' }}" type="submit">{{ $centre->is_bsl_official ? 'Remove badge' : 'Give BSL Verified' }}</button></form>
                        @else
                            <span class="help">Classify as a direct BSL centre first.</span>
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody></table>
        </div>
        @include('admin.partials.pagination', ['page' => $centres])
    @else
        <div class="empty-state"><span class="empty-icon"><x-admin-icon name="building" /></span><strong>No matching centres</strong><p>Try another name or city, or add an institute profile.</p></div>
    @endif
</section>
@endsection
