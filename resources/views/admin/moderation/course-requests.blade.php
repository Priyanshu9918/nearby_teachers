@extends('admin.layout')
@section('title', 'Course approvals')
@section('breadcrumb', 'Course approvals')
@section('content')
<div class="page-heading"><div><p class="eyebrow">Review &amp; protect</p><h1>Course approvals</h1><p>New provider courses remain hidden until approved. Approval adds the course to the catalogue. Teacher/institute requests also add it to the submitting profile.</p></div><span class="count-pill">{{ $requests->total() }} requests</span></div>
<section class="panel">
    <div class="panel-head"><form method="get"><label for="request-status">Status</label><select id="request-status" name="status">@foreach (['pending', 'approved', 'rejected', 'cancelled'] as $option)<option value="{{ $option }}" @selected($status === $option)>{{ ucfirst($option) }}</option>@endforeach</select><button class="btn btn-small" type="submit">Filter</button></form></div>
    <div class="table-wrap"><table class="data-table"><thead><tr><th>Course</th><th>Submitted by</th><th>Status</th><th>Submitted</th><th>Actions</th></tr></thead><tbody>
    @forelse ($requests as $course)
        <tr><td>{{ $course->name }}</td><td>{{ $course->full_name ?? 'Deleted account' }} · {{ $course->profile_type === 'job' ? 'Job application' : ucfirst($course->profile_type) }} · ID {{ $course->user_id }}</td><td>{{ ucfirst($course->status) }}</td><td>{{ $course->created_at }}</td><td>
        @if ($course->status === 'pending')<div class="table-actions">@foreach (['approved' => 'Approve', 'rejected' => 'Reject'] as $value => $label)<form method="post" action="{{ route('admin.course-requests.review', ['id' => $course->id]) }}">@csrf @method('patch')<input type="hidden" name="status" value="{{ $value }}"><button class="btn btn-small {{ $value === 'approved' ? 'btn-primary' : 'btn-danger' }}" type="submit">{{ $label }}</button></form>@endforeach</div>@else — @endif
        </td></tr>
    @empty<tr><td colspan="5">No {{ $status }} course requests.</td></tr>@endforelse
    </tbody></table></div>
    @include('admin.partials.pagination', ['page' => $requests])
</section>
@endsection
