@extends('admin.layout')
@section('title', 'Conversation report')
@section('breadcrumb', 'Conversation report')
@section('content')
<div class="page-heading"><div><p class="eyebrow">Chat report</p><h1>Conversation history</h1><p>{{ $conversation->id }} · started {{ $conversation->created_at }}</p></div></div>
<section class="panel panel-body"><a href="{{ route('admin.reports.user', $conversation->learner_id) }}">User: {{ $conversation->learner_name }}</a> ↔ <a href="{{ route('admin.reports.user', $conversation->provider_id) }}">{{ ucfirst($conversation->provider_type) }}: {{ $conversation->provider_name }}</a><p class="help">Access to this history is recorded in the admin access report. Messages appear newest first. The read_at field alone does not imply implemented read receipts.</p></section>
@include('admin.reports.filters')
@if (collect(['q', 'from', 'to'])->contains(fn ($key) => filled($filters[$key] ?? null)))
<div class="filter-feedback" role="status">
    <strong>{{ number_format($messages->total()) }} matching messages</strong>
    @if (filled($filters['q'] ?? null))<span>Search: {{ $filters['q'] }}</span>@endif
    @if (filled($filters['from'] ?? null))<span>From: {{ $filters['from'] }}</span>@endif
    @if (filled($filters['to'] ?? null))<span>Through: {{ $filters['to'] }}</span>@endif
    <a href="{{ request()->url() }}">Clear all</a>
</div>
@endif
<section class="panel"><div class="table-wrap" tabindex="0" role="region" aria-label="Conversation messages"><table class="data-table"><thead><tr><th>Sender</th><th>Message</th><th>Sent at</th><th>Message ID</th></tr></thead><tbody>
@forelse ($messages as $message)<tr><td><a href="{{ route('admin.reports.user', $message->sender_id) }}">{{ $message->sender_name }}</a><span class="record-sub">{{ $message->sender_type }}</span></td><x-admin-table-cell column="body" :value="$message->body" /><x-admin-table-cell column="created_at" :value="$message->created_at" /><x-admin-table-cell column="id" :value="$message->id" /></tr>
@empty <tr><td colspan="4" class="empty-state">No matching messages.</td></tr> @endforelse
</tbody></table></div>@include('admin.reports.pagination', ['page' => $messages])</section>
@endsection
