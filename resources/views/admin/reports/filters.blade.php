@php
    $reportPresets = \App\Reporting\ReportFilters::presets();
    $searchPlaceholder = match ($report ?? null) {
        'seo-location-pages' => 'Path, country, district, village or ID',
        'seo-pages' => 'Path, country, city or ID',
        'users' => 'Name, email, phone or user ID',
        'support' => 'Subject, message, user name or ID',
        null => 'Message text',
        default => 'Search text, name, email or record ID',
    };
@endphp
<form method="get" action="{{ request()->url() }}" class="panel panel-body report-filter-panel">
    @if (isset($filters['per_page']))<input type="hidden" name="per_page" value="{{ $filters['per_page'] }}">@endif
    @if (!isset($report) || $timestamp !== null)
        <fieldset class="date-presets"><legend>Quick date range</legend><div>@foreach ($reportPresets as $key => $preset)<button type="submit" name="range" value="{{ $key }}" formnovalidate @class(['date-preset', 'date-preset-active' => ($filters['range'] ?? null) === $key]) aria-pressed="{{ ($filters['range'] ?? null) === $key ? 'true' : 'false' }}">{{ $preset['label'] }}</button>@endforeach</div></fieldset>
    @endif
    <div class="form-grid">
        <div class="field"><label for="q">Search</label><input id="q" name="q" type="search" value="{{ $filters['q'] ?? '' }}" placeholder="{{ $searchPlaceholder }}"></div>
        @if (!empty($userColumns))
        <div class="field"><label for="type">Participant account type</label><select name="type" id="type"><option value="">All types</option>@foreach (['learner' => 'Student', 'teacher' => 'Teacher', 'institute' => 'Institute'] as $value => $label)<option value="{{ $value }}" @selected(($filters['type'] ?? '') === $value)>{{ $label }}</option>@endforeach</select></div>
        <div class="field"><label for="user">User ID</label><input name="user" id="user" value="{{ $filters['user'] ?? '' }}"></div>
        @endif
        @if (($report ?? null) === 'support')
        <div class="field"><label for="status">Ticket status</label><select name="status" id="status"><option value="">All statuses</option>@foreach (['pending' => 'Pending', 'in_progress' => 'In progress', 'resolved' => 'Resolved', 'rejected' => 'Rejected'] as $value => $label)<option value="{{ $value }}" @selected(($filters['status'] ?? '') === $value)>{{ $label }}</option>@endforeach</select></div>
        @endif
        @if (!isset($report) || $timestamp !== null)
        <div class="field"><label for="from">From date</label><input type="date" name="from" id="from" value="{{ $filters['from'] ?? '' }}"></div>
        <div class="field"><label for="to">Through date</label><input type="date" name="to" id="to" value="{{ $filters['to'] ?? '' }}"></div>
        @endif
    </div>
    <div class="form-actions"><button class="btn btn-primary" type="submit">Apply filters</button><a class="btn" href="{{ request()->url() }}">Clear filters</a><button class="btn" type="submit" name="export" value="1">Export matching records CSV</button><button class="btn" type="submit" formaction="{{ route('admin.reports.export-all') }}"><x-admin-icon name="download" />Download all reports (Excel)</button></div>
    <p class="help">@if (!isset($report)) Dates filter messages by sent time. @elseif ($timestamp !== null) Dates filter by {{ str_replace('_', ' ', $timestamp) }}. @endif Timezone: {{ config('app.timezone') }}. Exports include every matching row, across all pages.</p>
</form>
