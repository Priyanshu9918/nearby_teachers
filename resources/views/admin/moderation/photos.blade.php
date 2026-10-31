@extends('admin.layout')
@section('title', 'Photos to approve')
@section('breadcrumb', 'Photo review')
@section('content')
<div class="page-heading">
    <div><p class="eyebrow">Review &amp; protect</p><h1>Photos to approve</h1><p>Review profile photos before they appear in the app. Decisions take effect right away.</p></div>
    <span class="count-pill">{{ number_format($photos->total()) }} waiting</span>
</div>
<section class="panel" aria-labelledby="photo-queue-heading">
    <div class="panel-head"><div><h2 id="photo-queue-heading">Photo review queue</h2><p>Approve a suitable profile photo or reject it to keep it off public profiles.</p></div></div>
    @if ($photos->count())
        <div class="table-wrap" tabindex="0" role="region" aria-label="Photos waiting for review">
            <table class="data-table moderation-table"><thead><tr><th scope="col">Photo</th><th scope="col">Account</th><th scope="col">Profile last updated</th><th scope="col">Decision</th></tr></thead><tbody>
            @foreach ($photos as $photo)
                <tr>
                    <td class="photo-preview">@if ($photo->avatar_url)<img src="{{ $photo->avatar_url }}" alt="Submitted profile photo for {{ $photo->full_name }}" loading="lazy" referrerpolicy="no-referrer">@else<span class="photo-placeholder" aria-label="No photo preview">No preview</span>@endif</td>
                    <td><strong class="record-name">{{ $photo->full_name }}</strong><span class="record-sub">{{ ucfirst($photo->account_type) }} · ID {{ $photo->id }}</span><a class="inline-link" href="{{ route('admin.reports.user', $photo->id) }}">Open user report</a></td>
                    <td>{{ $photo->updated_at ? \Illuminate\Support\Carbon::parse($photo->updated_at)->format('M j, Y · g:i A') : '—' }}</td>
                    <td><div class="table-actions">
                        <form method="post" action="{{ route('admin.moderation.photos.review', ['userId' => $photo->id]) }}">@csrf @method('patch')<input type="hidden" name="status" value="approved"><button class="btn btn-small btn-primary" type="submit">Approve</button></form>
                        <form method="post" action="{{ route('admin.moderation.photos.review', ['userId' => $photo->id]) }}">@csrf @method('patch')<input type="hidden" name="status" value="rejected"><button class="btn btn-small btn-danger" type="submit">Reject</button></form>
                    </div></td>
                </tr>
            @endforeach
            </tbody></table>
        </div>
        @include('admin.partials.pagination', ['page' => $photos])
    @else
        <div class="empty-state"><span class="empty-icon"><x-admin-icon name="check" /></span><strong>You're all caught up</strong><p>New photos waiting for review will appear here.</p></div>
    @endif
</section>
@endsection
