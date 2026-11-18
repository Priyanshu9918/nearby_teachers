@extends('admin.layout')
@section('title', 'Profile photos')
@section('breadcrumb', 'Photo review')
@section('content')
<div class="page-heading">
    <div><p class="eyebrow">Review &amp; protect</p><h1>Profile photos</h1><p>Teacher and institute photos appear immediately. Delete an unsuitable photo below. Student photos still require review.</p></div>
    <span class="count-pill">{{ number_format($photos->total()) }} photos</span>
</div>
<section class="panel" aria-labelledby="photo-queue-heading">
    <div class="panel-head"><div><h2 id="photo-queue-heading">Manage profile photos</h2><p>Remove teacher or institute photos, or review pending student photos.</p></div></div>
    @if ($photos->count())
        <div class="table-wrap" tabindex="0" role="region" aria-label="Profile photos">
            <table class="data-table moderation-table"><thead><tr><th scope="col">Photo</th><th scope="col">Account</th><th scope="col">Profile last updated</th><th scope="col">Actions</th></tr></thead><tbody>
            @foreach ($photos as $photo)
                <tr>
                    <td class="photo-preview">@if ($photo->avatar_url)<img src="{{ \App\Support\PublicMediaUrl::resolve($photo->avatar_url) }}" alt="Submitted profile photo for {{ $photo->full_name }}" loading="lazy" referrerpolicy="no-referrer">@else<span class="photo-placeholder" aria-label="No photo preview">No preview</span>@endif</td>
                    <td><strong class="record-name">{{ $photo->full_name }}</strong><span class="record-sub">{{ ucfirst($photo->account_type) }} · ID {{ $photo->id }}</span><a class="inline-link" href="{{ route('admin.reports.user', $photo->id) }}">Open user report</a></td>
                    <td>{{ $photo->updated_at ? \Illuminate\Support\Carbon::parse($photo->updated_at)->format('M j, Y · g:i A') : '—' }}</td>
                    <td><div class="table-actions">
                        @if (in_array($photo->account_type, ['teacher', 'institute'], true))
                        <form method="post" action="{{ route('admin.moderation.photos.delete', ['userId' => $photo->id]) }}" onsubmit="return confirm('Delete this profile photo?')">@csrf @method('delete')<button class="btn btn-small btn-danger" type="submit">Delete photo</button></form>
                        @else
                        <form method="post" action="{{ route('admin.moderation.photos.review', ['userId' => $photo->id]) }}">@csrf @method('patch')<input type="hidden" name="status" value="approved"><button class="btn btn-small btn-primary" type="submit">Approve</button></form>
                        <form method="post" action="{{ route('admin.moderation.photos.review', ['userId' => $photo->id]) }}">@csrf @method('patch')<input type="hidden" name="status" value="rejected"><button class="btn btn-small btn-danger" type="submit">Reject</button></form>
                    @endif
                    </div></td>
                </tr>
            @endforeach
            </tbody></table>
        </div>
        @include('admin.partials.pagination', ['page' => $photos])
    @else
        <div class="empty-state"><span class="empty-icon"><x-admin-icon name="check" /></span><strong>You're all caught up</strong><p>Uploaded provider photos and pending student photos will appear here.</p></div>
    @endif
</section>
@endsection
