@extends('admin.layout')
@section('title', $title)
@section('breadcrumb', $title)
@section('content')
<div class="page-heading"><div><p class="eyebrow">Project reports</p><h1>{{ $title }}</h1><p>Search records, inspect linked users and export matching results.</p></div><a class="btn" href="{{ route('admin.dashboard') }}#report-library">All reports <x-admin-icon name="arrow" /></a></div>
<div class="panel panel-body report-picker"><label for="report-picker">Switch report</label><select id="report-picker" onchange="window.location.href=this.value">@foreach ($availableReports as $key => $entry)<option value="{{ route('admin.reports.index', ['report' => $key]) }}" @selected($report === $key)>{{ $entry->title }}</option>@endforeach</select></div>
@if ($report === 'logins')<div class="flash">Tracking begins when this feature is deployed. OTP verification is separate from login. Validation/throttle rejections before the controller are not logged. These are authentication events, not a list of currently online users.</div>@endif
@if ($report === 'activity')<div class="flash">History begins when tracking is enabled. Includes completed registration, booking creation/status changes and message creation metadata. Message text remains in conversation history. Other actions appear when their tracking is added.</div>@endif
@if (in_array($report, ['seo-pages', 'seo-location-pages'], true))<div class="flash">These are the active short URLs. Older language-in-path links redirect here; the full URL redirect history is under SEO URL ownership and redirects.</div>@endif
@include('admin.reports.filters')
<section class="panel"><p class="table-hint">Scroll horizontally to view all columns. IDs are shown in full.</p><div class="table-wrap" tabindex="0" role="region" aria-label="{{ $title }} records"><table class="data-table"><thead><tr><th>Details</th>@foreach ($columns as $column)<th>{{ str_replace('_', ' ', $column) }}</th>@endforeach</tr></thead><tbody>
@forelse ($records as $record)
    <tr><td>
        @if ($report === 'users')<a class="btn btn-small" href="{{ route('admin.reports.user', $record->id) }}">User report</a>
        @elseif ($report === 'conversations')<a class="btn btn-small" href="{{ route('admin.reports.conversation', $record->id) }}">Read messages</a>
        @elseif ($report === 'messages')<a class="btn btn-small" href="{{ route('admin.reports.conversation', $record->conversation_id) }}">Conversation</a>
        @elseif ($report === 'teacher-profiles' && $record->seo_public)<a class="btn btn-small" href="{{ \App\Services\SeoDirectory::url('/teacher/'.$record->public_page_slug) }}" target="_blank" rel="noopener noreferrer">Open teacher page <x-admin-icon name="arrow" /></a>
        @elseif ($report === 'teacher-profiles')<span>Needs eligible profile and country</span>
        @elseif ($report === 'seo-pages')
            @if ($record->eligible_teachers > 0 && $record->route_enabled)<a class="btn btn-small" href="{{ \App\Services\SeoDirectory::url($record->path) }}" target="_blank" rel="noopener noreferrer">Open page</a><span>Published</span><code class="seo-url-preview">{{ $record->path }}</code>@else<span>{{ $record->route_enabled ? 'No matching teachers' : 'Disabled' }}</span>@endif
        @elseif ($report === 'seo-location-pages')
            @if ($record->eligible_teachers > 0)<a class="btn btn-small" href="{{ \App\Services\SeoDirectory::url($record->path) }}" target="_blank" rel="noopener noreferrer">Open page</a><span>Published</span><code class="seo-url-preview">{{ $record->path }}</code>@else<span>No matching teachers</span>@endif
        @elseif ($report === 'seo-subject-routes')<a class="btn btn-small" href="{{ route('admin.seo.edit', ['id' => $record->id]) }}">Edit wording</a>
        @else — @endif
    </td>
    @foreach ($columns as $column)
        @php($value = $record->$column ?? null)
        <x-admin-table-cell :column="$column" :value="$value" :user-link="in_array($column, $userColumns, true)" />
    @endforeach</tr>
@empty <tr><td colspan="{{ count($columns) + 1 }}" class="empty-state">No matching records.</td></tr> @endforelse
</tbody></table></div>@include('admin.reports.pagination', ['page' => $records])</section>
@endsection
