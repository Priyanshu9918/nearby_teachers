@php
    $reportToday = now(config('app.timezone'))->startOfDay();
    $reportPresets = [
        'Today' => [$reportToday->toDateString(), $reportToday->toDateString()],
        '7 days' => [$reportToday->copy()->subDays(6)->toDateString(), $reportToday->toDateString()],
        '30 days' => [$reportToday->copy()->subDays(29)->toDateString(), $reportToday->toDateString()],
        'This month' => [$reportToday->copy()->startOfMonth()->toDateString(), $reportToday->toDateString()],
        'Last month' => [$reportToday->copy()->subMonthNoOverflow()->startOfMonth()->toDateString(), $reportToday->copy()->subMonthNoOverflow()->endOfMonth()->toDateString()],
        '90 days' => [$reportToday->copy()->subDays(89)->toDateString(), $reportToday->toDateString()],
        'This year' => [$reportToday->copy()->startOfYear()->toDateString(), $reportToday->toDateString()],
        'Since launch' => [config('reporting.launch_date'), $reportToday->toDateString()],
    ];
@endphp
<form method="get" class="panel panel-body report-filter-panel">
    @if (!isset($report) || $timestamp !== null)
        <fieldset class="date-presets"><legend>Quick date range</legend><div>@foreach ($reportPresets as $label => [$from, $to])<button type="button" class="date-preset" data-report-period data-from="{{ $from }}" data-to="{{ $to }}">{{ $label }}</button>@endforeach</div></fieldset>
    @endif
    <div class="form-grid">
        <div class="field"><label for="q">Search</label><input id="q" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="{{ isset($report) ? 'Name, email, phone, record ID or report text' : 'Message text' }}"></div>
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
    <div class="form-actions"><button class="btn btn-primary">Apply filters</button><a class="btn" href="{{ request()->url() }}">Reset</a><button class="btn" name="export" value="1">Export matching records CSV</button><a class="btn" href="{{ route('admin.reports.export-all', request()->only('q', 'user', 'type', 'from', 'to', 'status')) }}"><x-admin-icon name="download" />Download all reports (Excel)</a></div>
    <p class="help">Dates use {{ config('app.timezone') }}. Exports include every matching row, across all pages.</p>
</form>
