@extends('admin.layout')
@section('title', 'User report')
@section('breadcrumb', 'User report')
@section('content')
<div class="page-heading"><div><p class="eyebrow">{{ $user->account_type === 'learner' ? 'Student' : ucfirst($user->account_type) }}</p><h1>{{ $user->full_name }}</h1><p>{{ $user->email }} · {{ $user->phone }}</p></div><a class="btn" href="{{ route('admin.reports.index', ['report' => 'users']) }}">All users</a></div>
<section class="panel"><div class="panel-head"><h2>Activity and related records</h2></div><div class="panel-body" style="display:flex;gap:8px;flex-wrap:wrap">@foreach ($related as $key => $entry)<a class="btn" href="{{ route('admin.reports.index', ['report' => $key, 'user' => $user->id]) }}">{{ $entry->title }}</a>@endforeach</div></section>
@foreach (['User and registration details' => $user, 'Provider profile' => $profile] as $label => $details)
@if ($details)<section class="panel"><div class="panel-head"><h2>{{ $label }}</h2></div><div class="table-wrap"><table class="data-table"><tbody>@foreach ((array) $details as $key => $value)<tr><th>{{ str_replace('_', ' ', $key) }}</th><x-admin-table-cell :column="$key" :value="$value" /></tr>@endforeach</tbody></table></div></section>@endif
@endforeach
<section class="panel panel-body"><strong>Subjects</strong><p>{{ $subjects->join(', ') ?: 'No subjects assigned.' }}</p></section>
@endsection
