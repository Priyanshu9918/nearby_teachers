@extends('admin.layout')
@section('title', $title)
@section('breadcrumb', $title)
@section('content')
<div class="page-heading"><div><p class="eyebrow">Project reports</p><h1>{{ $title }}</h1><p>Search records, inspect linked users and export matching results.</p></div><a class="btn" href="{{ route('admin.dashboard') }}#report-library">All reports <x-admin-icon name="arrow" /></a></div>
<div class="panel panel-body report-picker"><label for="report-picker">Switch report</label><select id="report-picker" onchange="window.location.href=this.value">@foreach ($availableReports as $key => $entry)<option value="{{ route('admin.reports.index', ['report' => $key]) }}" @selected($report === $key)>{{ $entry->title }}</option>@endforeach</select></div>
@if ($report === 'logins')<div class="flash">Tracking begins when this feature is deployed. OTP verification is separate from login. Validation/throttle rejections before the controller are not logged. These are authentication events, not a list of currently online users.</div>@endif
@if ($report === 'activity')<div class="flash">History begins when tracking is enabled. Includes completed registration, booking creation/status changes and message creation metadata. Message text remains in conversation history. Other actions appear when their tracking is added.</div>@endif
@include('admin.reports.filters')
<section class="panel"><div class="table-wrap"><table class="data-table"><thead><tr><th>Details</th>@foreach ($columns as $column)<th>{{ str_replace('_', ' ', $column) }}</th>@endforeach</tr></thead><tbody>
@forelse ($records as $record)
    <tr><td>
        @if ($report === 'users')<a class="btn btn-small" href="{{ route('admin.reports.user', $record->id) }}">User report</a>
        @elseif ($report === 'conversations')<a class="btn btn-small" href="{{ route('admin.reports.conversation', $record->id) }}">Read messages</a>
        @elseif ($report === 'messages')<a class="btn btn-small" href="{{ route('admin.reports.conversation', $record->conversation_id) }}">Conversation</a>
        @else — @endif
    </td>
    @foreach ($columns as $column)
        @php($value = $record->$column ?? null)
        <td style="max-width:360px;white-space:normal;overflow-wrap:anywhere">
        @if ($value !== null && in_array($column, $userColumns, true))<a href="{{ route('admin.reports.user', $value) }}">{{ $value }}</a>
        @elseif (is_string($value) && mb_strlen($value) > 160)<details><summary>{{ \Illuminate\Support\Str::limit($value, 120) }}</summary><div style="white-space:pre-wrap">{{ $value }}</div></details>
        @else {{ $value === null ? '—' : $value }} @endif
        </td>
    @endforeach</tr>
@empty <tr><td colspan="{{ count($columns) + 1 }}" class="empty-state">No matching records.</td></tr> @endforelse
</tbody></table></div>@include('admin.reports.pagination', ['page' => $records])</section>
@endsection
