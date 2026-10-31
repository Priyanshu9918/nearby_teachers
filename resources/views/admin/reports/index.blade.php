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
@if ($comparison)
    <section class="period-comparison" aria-label="Comparison with the previous period">
        <div><span class="eyebrow">Period comparison</span><strong>{{ number_format($comparison['current']) }} records</strong><small>{{ \Illuminate\Support\Carbon::parse($comparison['from'])->format('M j, Y') }} – {{ \Illuminate\Support\Carbon::parse($comparison['to'])->format('M j, Y') }}</small></div>
        <span class="comparison-divider" aria-hidden="true"></span>
        <div><strong>{{ number_format($comparison['previous']) }} records</strong><small>Previous period · {{ \Illuminate\Support\Carbon::parse($comparison['previous_from'])->format('M j, Y') }} – {{ \Illuminate\Support\Carbon::parse($comparison['previous_to'])->format('M j, Y') }}</small></div>
        <span @class(['comparison-change', 'comparison-up' => $comparison['difference'] > 0, 'comparison-down' => $comparison['difference'] < 0])>{{ $comparison['difference'] > 0 ? '+' : '' }}{{ number_format($comparison['difference']) }} vs previous</span>
    </section>
@endif
@if (collect(['q', 'user', 'type', 'status', 'from', 'to'])->contains(fn ($key) => filled($filters[$key] ?? null)))
<div class="filter-feedback" role="status">
    <strong>{{ number_format($records->total()) }} matching records</strong>
    @if (filled($filters['q'] ?? null))<span>Search: {{ $filters['q'] }}</span>@endif
    @if (filled($filters['type'] ?? null))<span>Account: {{ ucfirst($filters['type']) }}</span>@endif
    @if (filled($filters['user'] ?? null))<span>User ID: {{ $filters['user'] }}</span>@endif
    @if (filled($filters['status'] ?? null))<span>Status: {{ ucfirst(str_replace('_', ' ', $filters['status'])) }}</span>@endif
    @if (filled($filters['from'] ?? null))<span>From: {{ $filters['from'] }}</span>@endif
    @if (filled($filters['to'] ?? null))<span>Through: {{ $filters['to'] }}</span>@endif
    <a href="{{ request()->url() }}">Clear all</a>
</div>
@endif
<section class="panel"><p class="table-hint">Scroll horizontally to view all columns. IDs are shown in full.</p><div class="table-wrap" tabindex="0" role="region" aria-label="{{ $title }} records"><table class="data-table"><thead><tr><th>Details</th>@foreach ($columns as $column)<th>{{ str_replace('_', ' ', $column) }}</th>@endforeach</tr></thead><tbody>
@forelse ($records as $record)
    <tr><td>
        @if ($report === 'users')<a class="btn btn-small" href="{{ route('admin.reports.user', $record->id) }}">User report</a>
        @elseif ($report === 'reports')
            @if ($record->screenshot_available)<a class="btn btn-small" href="{{ route('admin.reports.safety-screenshot', ['id' => $record->id]) }}">View screenshot</a>@endif
            <div class="table-actions safety-actions">
                @if ($record->status === 'open')
                    @foreach (['reviewed' => 'Mark reviewed', 'dismissed' => 'Dismiss'] as $status => $label)
                        <form method="post" action="{{ route('admin.moderation.safety.update', ['reportId' => $record->id]) }}" @if($status === 'dismissed') onsubmit="return confirm('Dismiss this safety report?')" @endif>@csrf @method('patch')<input type="hidden" name="status" value="{{ $status }}"><button class="btn btn-small {{ $status === 'dismissed' ? 'btn-danger' : '' }}" type="submit">{{ $label }}</button></form>
                    @endforeach
                @else
                    <form method="post" action="{{ route('admin.moderation.safety.update', ['reportId' => $record->id]) }}">@csrf @method('patch')<input type="hidden" name="status" value="open"><button class="btn btn-small" type="submit">Reopen</button></form>
                @endif
            </div>
        @elseif ($report === 'support')
            <div class="table-actions">
                @foreach (['pending' => 'Pending', 'in_progress' => 'In progress', 'resolved' => 'Resolved', 'rejected' => 'Rejected'] as $status => $label)
                    @if ($record->status !== $status)
                        <form method="post" action="{{ route('admin.support.tickets.status', ['id' => $record->id]) }}">
                            @csrf @method('patch')
                            <input type="hidden" name="status" value="{{ $status }}">
                            <button class="btn btn-small {{ $status === 'rejected' ? 'btn-danger' : '' }}" type="submit">{{ $label }}</button>
                        </form>
                    @endif
                @endforeach
            </div>
        @elseif ($report === 'conversations')<a class="btn btn-small" href="{{ route('admin.reports.conversation', $record->id) }}">Read messages</a>
        @elseif ($report === 'messages')<a class="btn btn-small" href="{{ route('admin.reports.conversation', $record->conversation_id) }}">Conversation</a>
        @elseif ($report === 'teacher-profiles' && $record->seo_public)<a class="btn btn-small" href="{{ \App\Services\SeoDirectory::url('/teacher/'.$record->public_page_slug) }}" target="_blank" rel="noopener noreferrer">Open teacher page <x-admin-icon name="arrow" /></a>
        @elseif ($report === 'teacher-profiles')<span>Needs eligible profile and country</span>
        @elseif ($report === 'institute-profiles' && $record->seo_public)<a class="btn btn-small" href="{{ \App\Services\SeoDirectory::url('/institute/'.$record->public_page_slug) }}" target="_blank" rel="noopener noreferrer">Open institute page <x-admin-icon name="arrow" /></a>
        @elseif ($report === 'institute-profiles')<span>Needs eligible profile and country</span>
        @elseif ($report === 'seo-pages')
            @if ($record->eligible_providers > 0 && $record->route_enabled)<a class="btn btn-small" href="{{ \App\Services\SeoDirectory::url($record->path) }}" target="_blank" rel="noopener noreferrer">Open page</a><span>Published</span><code class="seo-url-preview">{{ $record->path }}</code>@else<span>{{ $record->route_enabled ? 'No matching providers' : 'Disabled' }}</span>@endif
        @elseif ($report === 'seo-location-pages')
            @if ($record->eligible_providers > 0)<a class="btn btn-small" href="{{ \App\Services\SeoDirectory::url($record->path) }}" target="_blank" rel="noopener noreferrer">Open page</a><span>Published</span><code class="seo-url-preview">{{ $record->path }}</code>@else<span>No matching providers</span>@endif
        @elseif ($report === 'seo-subject-routes')<a class="btn btn-small" href="{{ route('admin.seo.edit', ['id' => $record->id]) }}">Edit wording</a>
        @else — @endif
    </td>
    @foreach ($columns as $column)
        @php($value = $record->$column ?? null)
        @if ($report === 'reports' && $column === 'target_type' && $value === null) @php($value = 'general') @endif
        <x-admin-table-cell :column="$column" :value="$value" :user-link="in_array($column, $userColumns, true)" />
    @endforeach</tr>
@empty <tr><td colspan="{{ count($columns) + 1 }}" class="empty-state">No matching records.</td></tr> @endforelse
</tbody></table></div>@include('admin.reports.pagination', ['page' => $records])</section>
@endsection
