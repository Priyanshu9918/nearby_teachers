@extends('admin.layout')
@section('title', 'Deactivated accounts')
@section('breadcrumb', 'Deactivated accounts')
@section('content')
<div class="page-heading">
    <div><p class="eyebrow">Review &amp; protect</p><h1>Deactivated accounts</h1><p>Restore an account when its owner is ready to use the platform again. Suspended accounts still need a separate suspension review.</p></div>
    <div class="heading-actions"><span class="count-pill">{{ number_format($users->total()) }} deactivated</span><a class="btn" href="{{ route('admin.reports.index', ['report' => 'users']) }}">Find a user <x-admin-icon name="arrow" /></a></div>
</div>
<section class="panel" aria-labelledby="deactivated-users-heading">
    <div class="panel-head"><div><h2 id="deactivated-users-heading">Accounts awaiting reactivation</h2><p>Restoring an account keeps its history and existing profile.</p></div></div>
    @if ($users->count())
        <div class="table-wrap" tabindex="0" role="region" aria-label="Deactivated user accounts">
            <table class="data-table"><thead><tr><th scope="col">Account</th><th scope="col">Contact</th><th scope="col">Deactivated</th><th scope="col">Other status</th><th scope="col">Action</th></tr></thead><tbody>
            @foreach ($users as $user)
                <tr>
                    <td><strong class="record-name">{{ $user->full_name }}</strong><span class="record-sub">{{ ucfirst($user->account_type) }} · ID {{ $user->id }}</span><a class="inline-link" href="{{ route('admin.reports.user', $user->id) }}">Open user report</a></td>
                    <td>{{ $user->email ?: $user->phone ?: 'No contact saved' }}</td>
                    <td>{{ \Illuminate\Support\Carbon::parse($user->deactivated_at)->format('M j, Y · g:i A') }}</td>
                    <td>@if ($user->suspended_at)<span class="status-pill status-danger">Suspended</span>@else<span class="status-pill status-muted">None</span>@endif</td>
                    <td><form method="post" action="{{ route('admin.moderation.users.reactivate', ['userId' => $user->id]) }}" onsubmit="return confirm('Reactivate this account?')">@csrf<button class="btn btn-small btn-primary" type="submit">Reactivate account</button></form></td>
                </tr>
            @endforeach
            </tbody></table>
        </div>
        @include('admin.partials.pagination', ['page' => $users])
    @else
        <div class="empty-state"><span class="empty-icon"><x-admin-icon name="check" /></span><strong>No deactivated accounts</strong><p>There are no accounts to reactivate.</p></div>
    @endif
</section>
@endsection
