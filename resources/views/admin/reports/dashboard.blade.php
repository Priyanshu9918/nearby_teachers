@extends('admin.layout')
@inject('adminNavigation', 'App\Support\AdminNavigation')
@section('title', 'BSL admin overview')
@section('breadcrumb', 'Overview')
@section('content')
<div class="admin-welcome">
    <div><p class="eyebrow">BSL TEAM · ADMIN DASHBOARD</p><h1>Run the platform from one screen</h1><p>Review the work waiting for your team, manage accounts and open owner reports.</p></div>
    <div class="heading-actions"><a class="btn btn-primary" href="{{ route('admin.create', ['section' => 'teachers']) }}"><x-admin-icon name="plus" />Add a teacher</a><a class="btn" href="#report-library"><x-admin-icon name="document" />Owner reports</a><a class="btn" href="{{ route('admin.reports.export-all', ['from' => $dashboardFrom->toDateString(), 'to' => $dashboardTo->toDateString()]) }}"><x-admin-icon name="download" />Download all as Excel</a></div>
</div>

@php
    $ownerReportPresets = \App\Reporting\ReportFilters::presets();
    $formatOwnerMetric = static function (array $metric): string {
        if ($metric['value'] === null) return '—';
        if ($metric['kind'] === 'percent') return number_format($metric['value'], 1).'%';
        if ($metric['kind'] === 'rating') return number_format($metric['value'], 1).' / 5';
        return number_format($metric['value']);
    };
@endphp
<section class="owner-period-panel panel" aria-labelledby="owner-period-heading">
    <div class="panel-head"><div><h2 id="owner-period-heading">Owner report · compare activity</h2><p>Select a period to compare it with the same length immediately before it.</p></div><span class="count-pill">{{ $dashboardFrom->format('M j') }} – {{ $dashboardTo->format('M j, Y') }}</span></div>
    <div class="panel-body">
        <form method="get" action="{{ route('admin.dashboard') }}" class="owner-period-form">
            <fieldset class="date-presets"><legend>Quick date range</legend><div>@foreach ($ownerReportPresets as $key => $preset)<button type="submit" name="range" value="{{ $key }}" formnovalidate @class(['date-preset', 'date-preset-active' => $ownerRange === $key]) aria-pressed="{{ $ownerRange === $key ? 'true' : 'false' }}">{{ $preset['label'] }}</button>@endforeach</div></fieldset>
            <div class="owner-date-inputs"><div class="field"><label for="owner-from">From date</label><input type="date" id="owner-from" name="from" value="{{ $dashboardFrom->toDateString() }}" required></div><div class="field"><label for="owner-to">Through date</label><input type="date" id="owner-to" name="to" value="{{ $dashboardTo->toDateString() }}" required></div><button class="btn btn-primary" type="submit">Apply dates</button></div>
            <p class="help">Data is grouped by {{ config('app.timezone') }} dates. “Since launch” starts {{ \Illuminate\Support\Carbon::parse(config('reporting.launch_date'))->format('M j, Y') }}.</p>
        </form>
        <div class="owner-period-grid">
            @foreach ($ownerPeriodMetrics as $key => $metric)
                @php
                    $currentValue = $metric['value'];
                    $previousValue = $ownerPreviousMetrics[$key]['value'];
                    $difference = ($currentValue === null || $previousValue === null) ? null : $currentValue - $previousValue;
                    $negativeWhenUp = in_array($key, ['empty_searches', 'safety_reports'], true);
                    $trendClass = $difference === null || $difference === 0 ? 'owner-trend-same' : ((($difference > 0) !== $negativeWhenUp) ? 'owner-trend-good' : 'owner-trend-bad');
                    if ($difference === null) $trendText = 'No comparison';
                    elseif ($difference === 0) $trendText = 'Same as previous';
                    elseif ($metric['kind'] === 'percent') $trendText = ($difference > 0 ? '+' : '').number_format($difference, 1).' pts vs previous';
                    elseif ($metric['kind'] === 'rating') $trendText = ($difference > 0 ? '+' : '').number_format($difference, 1).' vs previous';
                    elseif ($previousValue == 0) $trendText = 'New this period';
                    else $trendText = ($difference > 0 ? '+' : '').number_format($difference / abs($previousValue) * 100).'% vs previous';
                @endphp
                <article class="owner-metric-card"><div class="owner-metric-title">{{ $metric['label'] }}</div><strong>{{ $formatOwnerMetric($metric) }}</strong><div class="owner-metric-compare"><span>Previous: {{ $formatOwnerMetric($ownerPreviousMetrics[$key]) }}</span><span class="owner-trend {{ $trendClass }}">{{ $trendText }}</span></div></article>
            @endforeach
        </div>
        <p class="owner-compare-caption">Previous period: {{ $dashboardPreviousFrom->format('M j, Y') }} – {{ $dashboardPreviousTo->format('M j, Y') }}. Signup, referral and contact metrics begin when their tracking was added; older events are not reconstructed.</p>
    </div>
</section>

<div class="stat-grid stat-grid-admin" aria-label="Current admin queues">
    <a class="stat-card stat-card-action stat-red" href="{{ route('admin.reports.index', ['report' => 'reports']) }}"><span class="stat-top">Safety reports <span class="stat-icon"><x-admin-icon name="flag" /></span></span><strong class="stat-value">{{ number_format($workCounts['open_safety']) }}</strong><span class="stat-bottom">Open · fraud appears first <x-admin-icon name="arrow" /></span></a>
    <a class="stat-card stat-card-action" href="{{ route('admin.moderation.photos') }}"><span class="stat-top">Photos to approve <span class="stat-icon"><x-admin-icon name="photo" /></span></span><strong class="stat-value">{{ number_format($workCounts['pending_photos']) }}</strong><span class="stat-bottom">Review profile photos <x-admin-icon name="arrow" /></span></a>
    <a class="stat-card stat-card-action" href="{{ route('admin.moderation.bsl') }}"><span class="stat-top">BSL Verified centres <span class="stat-icon"><x-admin-icon name="check" /></span></span><strong class="stat-value">{{ number_format($workCounts['bsl_centres']) }}</strong><span class="stat-bottom">Direct BSL centres only <x-admin-icon name="arrow" /></span></a>
    <a class="stat-card stat-card-action" href="{{ route('admin.reports.index', ['report' => 'job-applications']) }}"><span class="stat-top">New leads to review <span class="stat-icon"><x-admin-icon name="briefcase" /></span></span><strong class="stat-value">{{ number_format($workCounts['call_backs']) }}</strong><span class="stat-bottom">Jobs, franchise and support <x-admin-icon name="arrow" /></span></a>
</div>

<section class="panel action-center" aria-labelledby="action-center-heading">
    <div class="panel-head"><div><h2 id="action-center-heading">What would you like to do?</h2><p>Open the right queue directly. Changes are recorded for the admin team.</p></div><span class="count-pill">{{ number_format($workCounts['referral_points']) }} referral points</span></div>
    <div class="action-grid">
        <a class="workflow-card" href="{{ route('admin.reports.index', ['report' => 'reports']) }}"><span class="workflow-icon workflow-danger"><x-admin-icon name="flag" /></span><span><strong>Safety reports</strong><small>Review reports, with fraud first</small></span><x-admin-icon class="workflow-arrow" name="arrow" /></a>
        <a class="workflow-card" href="{{ route('admin.moderation.photos') }}"><span class="workflow-icon"><x-admin-icon name="photo" /></span><span><strong>Photos to approve</strong><small>Approve or reject submitted photos</small></span><x-admin-icon class="workflow-arrow" name="arrow" /></a>
        <a class="workflow-card" href="{{ route('admin.moderation.bsl') }}"><span class="workflow-icon workflow-verified"><x-admin-icon name="check" /></span><span><strong>BSL Verified</strong><small>Manage badges for direct BSL centres</small></span><x-admin-icon class="workflow-arrow" name="arrow" /></a>
        <a class="workflow-card" href="{{ route('admin.reports.index', ['report' => 'users']) }}"><span class="workflow-icon"><x-admin-icon name="users" /></span><span><strong>Users &amp; account access</strong><small>Open a user report to suspend or restore access</small></span><x-admin-icon class="workflow-arrow" name="arrow" /></a>
        <div class="workflow-card workflow-card-group"><span class="workflow-icon"><x-admin-icon name="chat" /></span><span><strong>Messages &amp; bookings</strong><small>Audited access to conversations and lessons</small><span class="workflow-links"><a href="{{ route('admin.reports.index', ['report' => 'conversations']) }}">Messages</a><a href="{{ route('admin.reports.index', ['report' => 'bookings']) }}">Bookings</a></span></span></div>
        <a class="workflow-card" href="{{ route('admin.moderation.duplicates') }}"><span class="workflow-icon"><x-admin-icon name="users" /></span><span><strong>Possible duplicates</strong><small>{{ number_format($workCounts['possible_duplicates']) }} profiles to compare by name and city</small></span><x-admin-icon class="workflow-arrow" name="arrow" /></a>
        <div class="workflow-card workflow-card-group"><span class="workflow-icon"><x-admin-icon name="briefcase" /></span><span><strong>Call-backs</strong><small>New jobs, franchise and contact requests</small><span class="workflow-links"><a href="{{ route('admin.reports.index', ['report' => 'job-applications']) }}">Jobs</a><a href="{{ route('admin.reports.index', ['report' => 'franchise-enquiries']) }}">Franchise</a><a href="{{ route('admin.reports.index', ['report' => 'support']) }}">Support</a></span></span></div>
        <a class="workflow-card" href="{{ route('admin.create', ['section' => 'teachers']) }}"><span class="workflow-icon workflow-add"><x-admin-icon name="plus" /></span><span><strong>Add a teacher</strong><small>Create a teacher profile during a call</small></span><x-admin-icon class="workflow-arrow" name="arrow" /></a>
        <div class="workflow-card workflow-card-group"><span class="workflow-icon"><x-admin-icon name="document" /></span><span><strong>Suggestions &amp; support</strong><small>Read requests from app users</small><span class="workflow-links"><a href="{{ route('admin.reports.index', ['report' => 'suggestions']) }}">Suggestions</a><a href="{{ route('admin.reports.index', ['report' => 'support']) }}">Support tickets</a></span></span></div>
        <div class="workflow-card workflow-card-group"><span class="workflow-icon"><x-admin-icon name="chart" /></span><span><strong>Analytics &amp; contact stats</strong><small>See site traffic and provider contact</small><span class="workflow-links"><a href="{{ route('admin.reports.index', ['report' => 'page-views']) }}">Website visits</a><a href="{{ route('admin.reports.index', ['report' => 'phone-reveals']) }}">Phone reveals</a></span></span></div>
    </div>
</section>

<div class="dashboard-columns dashboard-secondary">
    <section class="panel"><div class="panel-head"><div><h2>People on the platform</h2><p>Open account lists or view a detailed user report.</p></div><span class="count-pill">{{ number_format($counts['users']['total'] ?? 0) }} total</span></div>
        @foreach (['learner' => ['Students', 'students', 'user'], 'teacher' => ['Teachers', 'teachers', 'users'], 'institute' => ['Institutes', 'institutes', 'building']] as $type => [$label, $sectionKey, $icon])
            <div class="people-row"><span class="person-icon"><x-admin-icon :name="$icon" /></span><div class="people-info"><strong>{{ $label }}</strong><small>Accounts and profile details</small></div><span class="people-total">{{ number_format($types[$type] ?? 0) }}</span><a class="btn btn-small" href="{{ route('admin.index', ['section' => $sectionKey]) }}">Manage</a></div>
        @endforeach
    </section>
    <section class="panel"><div class="panel-head"><div><h2>New sign-ups</h2><p>Accounts created · last 30 days</p></div><span class="count-pill">{{ number_format($registered) }} completed</span></div><div class="panel-body">
        <div class="daily-chart">
        @forelse ($daily as $day)
            <div class="daily-row"><time datetime="{{ $day->day }}">{{ \Illuminate\Support\Carbon::parse($day->day)->format('d M') }}</time><meter min="0" max="{{ max(1, $daily->max('total')) }}" value="{{ $day->total }}" aria-label="User records created on {{ $day->day }}">{{ $day->total }}</meter><strong>{{ $day->total }}</strong></div>
        @empty <div class="empty-state"><strong>No new user records yet</strong><p>New account activity will appear here.</p></div> @endforelse
        </div><p class="help">Completed registrations are counted separately from accounts still filling in a profile.</p>
    </div></section>
</div>

<section id="report-library" aria-labelledby="report-library-heading">
    <div class="section-heading"><div><p class="eyebrow">Owner reports</p><h2 id="report-library-heading">Explore reports by task</h2><p>Search, filter dates and export matching records as CSV.</p></div><div class="library-search"><x-admin-icon name="search" /><input type="search" id="report-library-search" placeholder="Search reports…" aria-label="Search report library"></div></div>
    <div class="report-library">
    @foreach ($adminNavigation->groups() as $label => $group)
        <section class="report-group" data-report-group data-label="{{ $label }}"><h3><x-admin-icon :name="$group['icon']" />{{ $label }}</h3>
            @foreach ($group['items'] as $item)<a class="report-row" data-report-link href="{{ $item['url'] }}"><span>{{ $item['label'] }}</span><strong>{{ number_format($counts[$item['key']]['total'] ?? 0) }}</strong></a>@endforeach
        </section>
    @endforeach
    </div><p id="report-library-empty" class="empty-state" role="status" hidden>No matching reports. Try “users”, “messages” or “support”.</p>
</section>
<p class="report-notes">All-time totals reflect stored records. Dates use {{ config('app.timezone') }}. Login and business history start when their tracking was enabled.</p>
@endsection
