@extends('admin.layout')
@inject('adminNavigation', 'App\Support\AdminNavigation')
@section('title', 'Overview')
@section('breadcrumb', 'Overview')
@section('content')
<div class="page-heading">
    <div><p class="eyebrow">Your workspace, at a glance</p><h1>Welcome back, Admin</h1><p>Keep up with your people, conversations and everything in between.</p></div>
    <div class="heading-actions"><a class="btn" href="{{ route('admin.reports.index', ['report' => 'conversations']) }}"><x-admin-icon name="chat" />View chats</a><a class="btn btn-primary" href="{{ route('admin.reports.index', ['report' => 'users']) }}">View all users<x-admin-icon name="arrow" /></a></div>
</div>
<div class="stat-grid">
    @foreach (['users' => ['Total users', 'users', 'Student, teacher & institute accounts'], 'conversations' => ['Conversations', 'chat', 'Explore who is talking to whom'], 'bookings' => ['Lesson bookings', 'calendar', 'All booking requests'], 'messages' => ['Messages', 'document', 'Browse messages by sender']] as $key => [$label, $icon, $hint])
    <a @class(['stat-card', 'featured' => $loop->first]) href="{{ route('admin.reports.index', ['report' => $key]) }}"><span class="stat-top">{{ $label }}<span class="stat-icon"><x-admin-icon :name="$icon" /></span></span><strong class="stat-value">{{ number_format($counts[$key]['total'] ?? 0) }}</strong><span class="stat-bottom">{{ $hint }}<x-admin-icon name="arrow" /></span></a>
    @endforeach
</div>
<div class="dashboard-columns">
    <section class="panel"><div class="panel-head"><div><h2>Your community</h2><p>Manage accounts or open a detailed user report.</p></div><span class="count-pill">All time</span></div>
        @foreach (['learner' => ['Students', 'students', 'user', 'Learners finding their next lesson'], 'teacher' => ['Teachers', 'teachers', 'users', 'Independent educators on your platform'], 'institute' => ['Institutes', 'institutes', 'building', 'Learning centers and education partners']] as $type => [$label, $sectionKey, $icon, $hint])
        <div class="people-row"><span class="person-icon"><x-admin-icon :name="$icon" /></span><div class="people-info"><strong>{{ $label }}</strong><small>{{ $hint }}</small></div><span class="people-total">{{ number_format($types[$type] ?? 0) }}</span><a class="btn btn-small" href="{{ route('admin.index', ['section' => $sectionKey]) }}" aria-label="Manage {{ strtolower($label) }}">Manage<x-admin-icon name="arrow" /></a></div>
        @endforeach
    </section>
    <section class="panel"><div class="panel-head"><div><h2>Registration overview</h2><p>New user records · last 30 days</p></div><x-admin-icon name="chart" /></div><div class="panel-body">
        <div class="registration-summary"><x-admin-icon name="check" /><span><strong>{{ number_format($registered) }}</strong> Completed registrations · all time</span></div>
        <div class="daily-chart">
        @forelse ($daily as $day)
            <div class="daily-row"><time datetime="{{ $day->day }}">{{ \Illuminate\Support\Carbon::parse($day->day)->format('d M Y') }}</time><meter min="0" max="{{ max(1, $daily->max('total')) }}" value="{{ $day->total }}" aria-label="User records created on {{ $day->day }}">{{ $day->total }}</meter><strong>{{ $day->total }}</strong></div>
        @empty <div class="empty-state"><strong>No new user records yet</strong><p>New account activity will appear here.</p></div> @endforelse
        </div>
        <p class="help">User creation includes incomplete accounts. Completed registrations are counted separately.</p>
    </div></section>
</div>
<section id="report-library" aria-labelledby="report-library-heading">
    <div class="section-heading"><div><h2 id="report-library-heading">Find the right report</h2><p>Everything you need, organized by what you want to do.</p></div><div class="library-search"><x-admin-icon name="search" /><input type="search" id="report-library-search" placeholder="Search reports…" aria-label="Search report library"></div></div>
    <div class="report-library">
    @foreach ($adminNavigation->groups() as $label => $group)
        <section class="report-group" data-report-group data-label="{{ $label }}"><h3><x-admin-icon :name="$group['icon']" />{{ $label }}</h3>
            @foreach ($group['items'] as $item)<a class="report-row" data-report-link href="{{ $item['url'] }}"><span>{{ $item['label'] }}</span><strong>{{ number_format($counts[$item['key']]['total'] ?? 0) }}</strong></a>@endforeach
        </section>
    @endforeach
    </div><p id="report-library-empty" class="empty-state" role="status" hidden>No matching reports. Try “users”, “messages” or “support”.</p>
</section>
<p class="report-notes">All-time totals reflect stored records. Dates use {{ config('app.timezone') }}. Login and business history are available from the time their tracking was enabled.</p>
@endsection
