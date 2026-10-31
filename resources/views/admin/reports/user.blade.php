@extends('admin.layout')
@section('title', 'User report')
@section('breadcrumb', 'User report')
@section('content')
<div class="page-heading"><div><p class="eyebrow">{{ $user->account_type === 'learner' ? 'Student' : ucfirst($user->account_type) }}</p><h1>{{ $user->full_name }}</h1><p>{{ $user->email }} · {{ $user->phone }}</p></div><a class="btn" href="{{ route('admin.reports.index', ['report' => 'users']) }}">All users</a></div>
<section class="panel account-safety-panel">
    <div class="panel-head"><div><h2>Account access</h2><p>Suspending blocks this account from using the platform. Add a clear reason for the team.</p></div>
        @if ($user->is_admin)<span class="status-pill status-muted">Admin account</span>
        @elseif ($user->suspended_at)<span class="status-pill status-danger">Suspended</span>
        @else<span class="status-pill status-good">Active</span>@endif
    </div>
    @if (! $user->is_admin)
        <div class="panel-body">
            @if ($user->suspended_at)
                <p class="suspension-reason"><strong>Reason on file</strong><br>{{ $user->suspended_reason ?: 'No reason entered.' }}</p>
                <form method="post" action="{{ route('admin.moderation.users.unsuspend', ['userId' => $user->id]) }}" onsubmit="return confirm('Restore access for this account?')">@csrf<button class="btn btn-primary" type="submit">Restore access</button></form>
            @else
                <form method="post" action="{{ route('admin.moderation.users.suspend', ['userId' => $user->id]) }}" onsubmit="return confirm('Suspend this account?')">@csrf
                    <div class="field"><label for="suspension-reason">Reason for suspension</label><textarea id="suspension-reason" name="reason" maxlength="1000" required placeholder="Briefly explain the issue for the admin team."></textarea></div>
                    <button class="btn btn-danger" type="submit">Suspend account</button>
                </form>
            @endif
        </div>
    @endif
</section>
<section class="panel"><div class="panel-head"><h2>Activity and related records</h2></div><div class="panel-body" style="display:flex;gap:8px;flex-wrap:wrap">@foreach ($related as $key => $entry)<a class="btn" href="{{ route('admin.reports.index', ['report' => $key, 'user' => $user->id]) }}">{{ $entry->title }}</a>@endforeach</div></section>
@foreach (['User and registration details' => $user, 'Provider profile' => $profile] as $label => $details)
@if ($details)<section class="panel"><div class="panel-head"><h2>{{ $label }}</h2></div><div class="table-wrap"><table class="data-table"><tbody>@foreach ((array) $details as $key => $value)<tr><th>{{ str_replace('_', ' ', $key) }}</th><x-admin-table-cell :column="$key" :value="$value" /></tr>@endforeach</tbody></table></div></section>@endif
@endforeach
<section class="panel panel-body"><strong>Subjects</strong><p>{{ $subjects->join(', ') ?: 'No subjects assigned.' }}</p></section>
@endsection
