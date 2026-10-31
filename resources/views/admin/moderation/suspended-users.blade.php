@extends('admin.layout')
@section('title', 'Suspended accounts')
@section('breadcrumb', 'Suspended accounts')
@section('content')
<div class="page-heading">
    <div><p class="eyebrow">Review &amp; protect</p><h1>Suspended accounts</h1><p>Review the reason and restore access when the issue is resolved. Admin accounts are excluded.</p></div>
    <div class="heading-actions"><span class="count-pill">{{ number_format($users->total()) }} suspended</span><a class="btn" href="{{ route('admin.reports.index', ['report' => 'users']) }}">Find a user <x-admin-icon name="arrow" /></a></div>
</div>
<section class="panel" aria-labelledby="suspended-users-heading">
    <div class="panel-head"><div><h2 id="suspended-users-heading">Accounts needing a decision</h2><p>Restoring an account clears its suspension reason.</p></div></div>
    @if ($users->count())
        <div class="table-wrap" tabindex="0" role="region" aria-label="Suspended user accounts">
            <table class="data-table"><thead><tr><th scope="col">Account</th><th scope="col">Contact</th><th scope="col">Reason</th><th scope="col">Suspended</th><th scope="col">Action</th></tr></thead><tbody>
            @foreach ($users as $user)
                <tr>
                    <td><strong class="record-name">{{ $user->full_name }}</strong><span class="record-sub">{{ ucfirst($user->account_type) }} · ID {{ $user->id }}</span><a class="inline-link" href="{{ route('admin.reports.user', $user->id) }}">Open user report</a></td>
                    <td>{{ $user->email ?: $user->phone ?: 'No contact saved' }}</td>
                    <td><span class="cell-value">{{ $user->suspended_reason ?: 'No reason entered' }}</span></td>
                    <td>{{ $user->suspended_at ? \Illuminate\Support\Carbon::parse($user->suspended_at)->format('M j, Y · g:i A') : '—' }}</td>
                    <td><form method="post" action="{{ route('admin.moderation.users.unsuspend', ['userId' => $user->id]) }}">@csrf<button class="btn btn-small btn-primary" type="submit">Restore access</button></form></td>
                </tr>
            @endforeach
            </tbody></table>
        </div>
        @include('admin.partials.pagination', ['page' => $users])
    @else
        <div class="empty-state"><span class="empty-icon"><x-admin-icon name="check" /></span><strong>No suspended accounts</strong><p>There are no suspended accounts to review.</p></div>
    @endif
</section>
@endsection
