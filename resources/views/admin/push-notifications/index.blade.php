@extends('admin.layout')
@section('title', 'Send push notification')
@section('content')
<div class="page-heading">
    <div><p class="eyebrow">Notifications</p><h1>Send push notification</h1><p>Select who should receive the message, review the live recipient count, then queue the campaign.</p></div>
    <div class="heading-actions"><a class="btn" href="{{ route('admin.reports.index', ['report' => 'push-campaigns']) }}">Campaign history</a><a class="btn" href="{{ route('admin.reports.index', ['report' => 'push-devices']) }}">Registered devices</a></div>
</div>
@unless ($messagingEnabled)
    <div class="flash flash-error" role="alert"><span aria-hidden="true">!</span><div><strong>Push sending is currently unavailable</strong><span>Firebase Cloud Messaging is not enabled. An administrator must complete the server setup before campaigns can be queued.</span></div></div>
@endunless
<section class="panel panel-body">
    <form method="post" action="{{ route('admin.push-notifications.store') }}" id="push-campaign-form">
        @csrf
        <div class="form-grid">
            <div class="field"><label for="target-type">Recipients</label><small>Choose the group of registered devices that should receive this notification.</small>
                <select name="target_type" id="target-type" required>
                    <option value="all" @selected(old('target_type', 'all') === 'all')>All active devices</option>
                    <option value="segment" @selected(old('target_type') === 'segment')>Account type or location</option>
                    {{-- Temporarily hidden; backend support remains available for later re-enablement.
                    <option value="user" @selected(old('target_type') === 'user')>Every device for one user</option>
                    <option value="device" @selected(old('target_type') === 'device')>One specific device</option>
                    --}}
                </select>
            </div>
            <div class="field audience-id-field"><label for="target-id" id="target-id-label">Target ID</label><input id="target-id" name="target_id" value="{{ old('target_id') }}" maxlength="36" autocomplete="off"><small id="target-id-help">This field is used when a specific target option is enabled.</small></div>
        </div>
        <div class="form-grid audience-segment-fields">
            <div class="field"><label for="account-type">Account type <span>(optional)</span></label><select name="account_type" id="account-type"><option value="">All account types</option>@foreach (['learner', 'teacher', 'institute'] as $type)<option value="{{ $type }}" @selected(old('account_type') === $type)>{{ ucfirst($type) }}</option>@endforeach</select></div>
            <div class="field"><label for="country">Country <span>(optional)</span></label><select name="country" id="country"><option value="">Loading countries…</option></select></div>
            <div class="field"><label for="state">State <span>(optional)</span></label><select name="state" id="state" disabled><option value="">Select a country first</option></select></div>
            <div class="field"><label for="city">City <span>(optional)</span></label><select name="city" id="city" disabled><option value="">Select a state first</option></select></div>
        </div>
        <p class="flash audience-count" id="audience-count" role="status" aria-live="polite">Loading audience preview…</p>
        <div class="form-grid">
            <div class="field"><label for="push-title">Notification title</label><input name="title" id="push-title" maxlength="120" value="{{ old('title') }}" required><small>Short heading shown in the user's notification.</small></div>
            <div class="field"><label for="deep-link">App deep link <span>(optional)</span></label><input name="deep_link" id="deep-link" maxlength="300" value="{{ old('deep_link') }}" placeholder="/feed"><small>Optional in-app path, for example <code>/feed</code>. Leave blank if no screen should open.</small></div>
        </div>
        <div class="field"><label for="push-body">Message</label><textarea name="body" id="push-body" maxlength="2000" required>{{ old('body') }}</textarea><small id="body-count">0 / 2,000 characters</small></div>
        <div class="field"><label for="confirmation">Confirmation</label><input name="confirmation" id="confirmation" autocomplete="off" required pattern="SEND" placeholder="Type SEND"><small>Type <strong>SEND</strong> to confirm that you reviewed the audience and message.</small></div>
        <div class="heading-actions"><button class="btn btn-primary" type="submit" @disabled(!$messagingEnabled)>Queue notification</button></div>
    </form>
</section>
<section class="panel panel-body">
    <h2>Delivery reports</h2>
    <p>Review campaign status, recipient counts and per-device delivery outcomes. “Accepted” means Firebase accepted the request; it does not guarantee that the device displayed the notification.</p>
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
    const body = document.getElementById('push-body');
    const bodyCount = document.getElementById('body-count');
    const country = document.getElementById('country');
    const state = document.getElementById('state');
    const city = document.getElementById('city');
    const geographyUrls = {
        countries: {{ Illuminate\Support\Js::from(url('/geo/countries')) }},
        states: {{ Illuminate\Support\Js::from(url('/geo/states')) }},
        cities: {{ Illuminate\Support\Js::from(url('/geo/cities')) }},
    };
    const initialLocation = {
        country: {{ Illuminate\Support\Js::from(old('country')) }},
        state: {{ Illuminate\Support\Js::from(old('state')) }},
        city: {{ Illuminate\Support\Js::from(old('city')) }},
    };
    let currentController;
    let stateController;
    let cityController;
    const replaceOptions = (select, placeholder, rows, selectedValue, labelFor) => {
        select.replaceChildren(new Option(placeholder, ''));
        rows.forEach((row) => {
            const option = new Option(labelFor(row), row.name);
            option.dataset.id = String(row.id);
            select.append(option);
        });
        if (selectedValue) {
            const match = [...select.options].find((option) =>
                option.value.toLocaleLowerCase() === String(selectedValue).toLocaleLowerCase()
                || option.dataset.code?.toLocaleLowerCase() === String(selectedValue).toLocaleLowerCase());
            if (match) match.selected = true;
        }
    };
    const showLoadFailure = (select, message) => {
        select.replaceChildren(new Option(message, ''));
        select.disabled = true;
    };
    const loadCities = async (selectedValue = '') => {
        if (cityController) cityController.abort();
        cityController = new AbortController();
        city.disabled = true;
        city.replaceChildren(new Option(state.value ? 'Loading cities…' : 'Select a state first', ''));
        const stateId = state.selectedOptions[0]?.dataset.id;
        if (!stateId) {
            refreshPreview();
            return;
        }
        try {
            const response = await fetch(`${geographyUrls.cities}?state_id=${encodeURIComponent(stateId)}`, {
                headers: {'Accept': 'application/json'}, credentials: 'same-origin', signal: cityController.signal,
            });
            if (!response.ok) throw new Error('Unable to load cities.');
            const result = await response.json();
            replaceOptions(city, 'Any city', result.cities || [], selectedValue, (row) => row.name);
            city.disabled = false;
            refreshPreview();
        } catch (error) {
            if (error.name !== 'AbortError') showLoadFailure(city, 'Unable to load cities');
        }
    };
    const loadStates = async (selectedState = '', selectedCity = '') => {
        if (stateController) stateController.abort();
        if (cityController) cityController.abort();
        stateController = new AbortController();
        state.disabled = true;
        state.replaceChildren(new Option(country.value ? 'Loading states…' : 'Select a country first', ''));
        city.disabled = true;
        city.replaceChildren(new Option('Select a state first', ''));
        const countryId = country.selectedOptions[0]?.dataset.id;
        if (!countryId) {
            refreshPreview();
            return;
        }
        try {
            const response = await fetch(`${geographyUrls.states}?country_id=${encodeURIComponent(countryId)}`, {
                headers: {'Accept': 'application/json'}, credentials: 'same-origin', signal: stateController.signal,
            });
            if (!response.ok) throw new Error('Unable to load states.');
            const result = await response.json();
            replaceOptions(state, 'Any state', result.states || [], selectedState, (row) => row.name);
            state.disabled = false;
            if (state.value) await loadCities(selectedCity);
            else refreshPreview();
        } catch (error) {
            if (error.name !== 'AbortError') showLoadFailure(state, 'Unable to load states');
        }
    };
    const loadCountries = async () => {
        country.disabled = true;
        try {
            const response = await fetch(geographyUrls.countries, {
                headers: {'Accept': 'application/json'}, credentials: 'same-origin',
            });
            if (!response.ok) throw new Error('Unable to load countries.');
            const result = await response.json();
            replaceOptions(country, 'Any country', result.countries || [], initialLocation.country, (row) => `${row.sortname} — ${row.name}`);
            [...country.options].forEach((option) => {
                const row = (result.countries || []).find((item) => item.name === option.value);
                if (row) option.dataset.code = row.sortname;
            });
            if (initialLocation.country) {
                const match = [...country.options].find((option) =>
                    option.value.toLocaleLowerCase() === String(initialLocation.country).toLocaleLowerCase()
                    || option.dataset.code?.toLocaleLowerCase() === String(initialLocation.country).toLocaleLowerCase());
                if (match) match.selected = true;
            }
            country.disabled = false;
            if (country.value) await loadStates(initialLocation.state, initialLocation.city);
            else refreshPreview();
        } catch (error) {
            showLoadFailure(country, 'Unable to load countries');
        }
    };
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
            const count = Number(result.recipientCount || 0);
            counter.textContent = count === 0
                ? 'No active devices match the selected audience.'
                : `${new Intl.NumberFormat().format(count)} active device${count === 1 ? '' : 's'} match the selected audience.`;
        } catch (error) {
            if (error.name !== 'AbortError') counter.textContent = error.message || 'Audience count is not available yet.';
        }
    };
    const updateBodyCount = () => {
        bodyCount.textContent = `${new Intl.NumberFormat().format(body.value.length)} / 2,000 characters`;
    };
    type.addEventListener('change', updateFields);
    ['target-id', 'account-type'].forEach((id) => {
        const field = document.getElementById(id);
        field.addEventListener('change', refreshPreview);
        field.addEventListener('input', refreshPreview);
    });
    country.addEventListener('change', () => loadStates());
    state.addEventListener('change', () => loadCities());
    city.addEventListener('change', refreshPreview);
    body.addEventListener('input', updateBodyCount);
    updateFields();
    updateBodyCount();
    loadCountries();
})();
</script>
@endsection
