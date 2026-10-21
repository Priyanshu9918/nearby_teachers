@extends('admin.layout')
@section('title', 'Project dashboard')
@section('breadcrumb', 'Project dashboard')
@section('content')
<div class="page-heading"><div><p class="eyebrow">Platform overview</p><h1>Project dashboard</h1><p>All-time totals and access to every business report. Times use {{ config('app.timezone') }}.</p></div></div>
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px">
    @foreach (['learner' => 'Students', 'teacher' => 'Teachers', 'institute' => 'Institutes'] as $type => $label)
        <a class="panel panel-body" style="text-decoration:none" href="{{ route('admin.reports.index', ['report' => 'users', 'type' => $type]) }}"><strong style="font-size:28px">{{ number_format($types[$type] ?? 0) }}</strong><p>{{ $label }}</p></a>
    @endforeach
    <div class="panel panel-body"><strong style="font-size:28px">{{ number_format($registered) }}</strong><p>Completed registrations</p><small>Other user records may be incomplete or created by admin.</small></div>
    @foreach ($counts as $key => $stat)
        <a class="panel panel-body" style="text-decoration:none" href="{{ route('admin.reports.index', ['report' => $key]) }}"><strong style="font-size:25px">{{ number_format($stat['total']) }}</strong><p>{{ $stat['label'] }}</p></a>
    @endforeach
</div>
<section class="panel"><div class="panel-head"><h2>User records created · last 30 days</h2></div><div class="panel-body">
    @forelse ($daily as $day)
        <div style="display:flex;gap:14px;align-items:center;margin-bottom:10px"><span>{{ $day->day }}</span><meter min="0" max="{{ max(1, $daily->max('total')) }}" value="{{ $day->total }}" style="width:50%">{{ $day->total }}</meter><strong>{{ $day->total }}</strong></div>
    @empty <p>No new user records in this period.</p> @endforelse
</div></section>
<p class="help">Login tracking starts after the reporting migration is deployed. Existing registration timestamps are available; historical logins cannot be reconstructed. Stored records reflect current data; deleted records are not an archive.</p>
@endsection
