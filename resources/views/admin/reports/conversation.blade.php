@extends('admin.layout')
@section('title', 'Conversation report')
@section('breadcrumb', 'Conversation report')
@section('content')
<div class="page-heading"><div><p class="eyebrow">Chat report</p><h1>Conversation history</h1><p>{{ $conversation->id }} · started {{ $conversation->created_at }}</p></div></div>
<section class="panel panel-body"><a href="{{ route('admin.reports.user', $conversation->learner_id) }}">Student: {{ $conversation->learner_name }}</a> ↔ <a href="{{ route('admin.reports.user', $conversation->provider_id) }}">{{ ucfirst($conversation->provider_type) }}: {{ $conversation->provider_name }}</a><p class="help">Access to this history is recorded in the admin access report. Messages appear newest first. The read_at field alone does not imply implemented read receipts.</p></section>
@include('admin.reports.filters')
<section class="panel"><div class="table-wrap"><table class="data-table"><thead><tr><th>Sender</th><th>Message</th><th>Sent at</th><th>Message ID</th></tr></thead><tbody>
@forelse ($messages as $message)<tr><td><a href="{{ route('admin.reports.user', $message->sender_id) }}">{{ $message->sender_name }}</a><span class="record-sub">{{ $message->sender_type }}</span></td><td style="white-space:pre-wrap;min-width:240px;max-width:650px;overflow-wrap:anywhere">{{ $message->body }}</td><td>{{ $message->created_at }}</td><td>{{ $message->id }}</td></tr>
@empty <tr><td colspan="4" class="empty-state">No matching messages.</td></tr> @endforelse
</tbody></table></div>@include('admin.reports.pagination', ['page' => $messages])</section>
@endsection
