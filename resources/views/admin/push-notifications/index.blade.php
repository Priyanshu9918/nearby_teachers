@extends('admin.layout')
@section('title', 'Send push notification')
@section('content')
<div class="page-heading">
    <div><p class="eyebrow">Notifications</p><h1>Send push notification</h1><p>Choose an audience, review its active device count, then confirm the campaign.</p></div>
    <div class="heading-actions"><a class="btn" href="{{ route('admin.reports.index', ['report' => 'push-campaigns']) }}">Campaign history</a><a class="btn" href="{{ route('admin.reports.index', ['report' => 'push-devices']) }}">Registered devices</a></div>
</div>
@unless ($messagingEnabled)
    <div class="flash flash-error" role="alert"><span aria-hidden="true">!</span><div><strong>FCM sending is disabled</strong>Set up Firebase Cloud Messaging credentials and enable it before sending campaigns.</div></div>
@endunless
<section class="panel panel-body">
    <form method="post" action="{{ route('admin.push-notifications.store') }}" id="push-campaign-form">
        @csrf
        <div class="form-grid">
            <div class="field"><label for="target-type">Audience</label>
                <select name="target_type" id="target-type" required>
                    @foreach (['all' => 'All active devices', 'segment' => 'Account type or location', 'user' => 'Every device for one user', 'device' => 'One specific device'] as $value => $label)
                        <option value="{{ $value }}" @selected(old('target_type', 'all') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="field audience-id-field"><label for="target-id" id="target-id-label">Target ID</label><input id="target-id" name="target_id" value="{{ old('target_id') }}" maxlength="36" autocomplete="off"><small id="target-id-help">Paste a user ID or a device ID from the Registered push devices report.</small></div>
        </div>
        <div class="form-grid audience-segment-fields">
            <div class="field"><label for="account-type">Account type</label><select name="account_type" id="account-type"><option value="">Any account type</option>@foreach (['learner', 'teacher', 'institute'] as $type)<option value="{{ $type }}" @selected(old('account_type') === $type)>{{ ucfirst($type) }}</option>@endforeach</select></div>
            <div class="field"><label for="city">City</label><input name="city" id="city" maxlength="120" value="{{ old('city') }}" placeholder="e.g. Kanpur"></div>
            <div class="field"><label for="state">State</label><input name="state" id="state" maxlength="120" value="{{ old('state') }}" placeholder="e.g. Uttar Pradesh"></div>
            <div class="field"><label for="country">Country</label><input name="country" id="country" maxlength="120" value="{{ old('country') }}" placeholder="e.g. India or IN"></div>
        </div>
        <p class="flash audience-count" id="audience-count" role="status" aria-live="polite">Loading audience preview…</p>
        <div class="form-grid">
            <div class="field"><label for="push-title">Notification title</label><input name="title" id="push-title" maxlength="120" value="{{ old('title') }}" required></div>
            <div class="field"><label for="deep-link">App deep link <span>(optional)</span></label><input name="deep_link" id="deep-link" maxlength="300" value="{{ old('deep_link') }}" placeholder="/feed"><small>Use an in-app path such as /feed. The app must validate the path before navigation.</small></div>
        </div>
        <div class="field"><label for="push-body">Message</label><textarea name="body" id="push-body" maxlength="2000" required>{{ old('body') }}</textarea></div>
        <div class="field"><label for="confirmation">Type SEND to confirm</label><input name="confirmation" id="confirmation" autocomplete="off" required pattern="SEND"><small>This confirmation is required for every campaign, including broadcasts.</small></div>
        <div class="heading-actions"><button class="btn btn-primary" type="submit" @disabled(!$messagingEnabled)>Queue campaign</button></div>
    </form>
</section>
<section class="panel panel-body">
    <h2>Delivery reports</h2>
    <p>See campaign filters and counts, device registrations, and per-device FCM outcomes. FCM accepted means the provider accepted the request; it does not confirm that the OS displayed the notification.</p>
    <p><a class="btn btn-small" href="{{ route('admin.reports.index', ['report' => 'push-campaigns']) }}">Campaigns</a> <a class="btn btn-small" href="{{ route('admin.reports.index', ['report' => 'push-deliveries']) }}">Delivery attempts</a> <a class="btn btn-small" href="{{ route('admin.reports.index', ['report' => 'push-devices']) }}">Devices and device IDs</a></p>
</section>
<script>
(() => {
    const form = document.getElementById('push-campaign-form');
    const type = document.getElementById('target-type');
    const targetId = document.getElementById('target-id');
    const idLabel = document.getElementById('target-id-label');
    const idHelp = document.getElementById('target-id-help');
    const idField = targetId.closest('.field');
    const segmentFields = document.querySelector('.audience-segment-fields');
    const counter = document.getElementById('audience-count');
    let currentController;
    const updateFields = () => {
        const usesId = type.value === 'user' || type.value === 'device';
        idField.hidden = !usesId;
        targetId.required = usesId;
        idLabel.textContent = type.value === 'device' ? 'Device ID' : 'User ID';
        idHelp.textContent = type.value === 'device'
            ? 'Copy the ID from the Registered push devices report. No FCM token is shown there.'
            : 'Use the exact user ID from the user report.';
        segmentFields.hidden = type.value !== 'segment';
        refreshPreview();
    };
    const refreshPreview = async () => {
        if (currentController) currentController.abort();
        currentController = new AbortController();
        const params = new URLSearchParams(new FormData(form));
        params.delete('_token');
        params.delete('title'); params.delete('body'); params.delete('deep_link'); params.delete('confirmation');
        counter.textContent = 'Checking matching devices…';
        try {
            const response = await fetch(`{{ route('admin.push-notifications.preview') }}?${params}`, {
                headers: {'Accept': 'application/json'}, credentials: 'same-origin', signal: currentController.signal,
            });
            const result = await response.json();
            if (!response.ok) throw new Error(result.message || 'Choose a valid audience to see the count.');
            counter.textContent = `${new Intl.NumberFormat().format(result.recipientCount)} active push-enabled device(s) match this audience.`;
        } catch (error) {
            if (error.name !== 'AbortError') counter.textContent = error.message || 'Audience count is not available yet.';
        }
    };
    type.addEventListener('change', updateFields);
    ['target-id', 'account-type', 'city', 'state', 'country'].forEach((id) => {
        const field = document.getElementById(id);
        field.addEventListener('change', refreshPreview);
        field.addEventListener('input', refreshPreview);
    });
    updateFields();
})();
</script>
@endsection
