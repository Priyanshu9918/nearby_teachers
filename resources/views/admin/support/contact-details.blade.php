@extends('admin.layout')
@section('title', 'Contact BSL')
@section('breadcrumb', 'Contact BSL')
@section('content')
<div class="page-heading">
    <div><p class="eyebrow">Support setup</p><h1>Contact BSL</h1><p>These public contact links appear in the app’s Contact BSL screen.</p></div>
    <a class="btn" href="{{ route('admin.reports.index', ['report' => 'support']) }}">View support tickets</a>
</div>

<form class="panel panel-body" method="post" action="{{ route('admin.support.contact.update') }}">
    @csrf
    @method('put')
    <div class="form-grid">
        <div class="field">
            <label for="whatsappNumber">WhatsApp number</label>
            <input id="whatsappNumber" name="whatsappNumber" type="tel" value="{{ old('whatsappNumber', $contact->whatsapp_number) }}" placeholder="+91 98765 43210" autocomplete="tel">
            <p class="help">Include the country code. The app creates the WhatsApp link automatically.</p>
        </div>
        <div class="field">
            <label for="email">Support email</label>
            <input id="email" name="email" type="email" value="{{ old('email', $contact->email) }}" placeholder="support@example.com" autocomplete="email">
        </div>
        <div class="field">
            <label for="linkedinUrl">LinkedIn profile or company URL</label>
            <input id="linkedinUrl" name="linkedinUrl" type="url" value="{{ old('linkedinUrl', $contact->linkedin_url) }}" placeholder="https://www.linkedin.com/in/…" inputmode="url">
        </div>
        <div class="field">
            <label for="linkedinName">LinkedIn contact name</label>
            <input id="linkedinName" name="linkedinName" value="{{ old('linkedinName', $contact->linkedin_name) }}" placeholder="Prateek Gupta" maxlength="160">
        </div>
        <div class="field">
            <label for="linkedinTitle">LinkedIn title</label>
            <input id="linkedinTitle" name="linkedinTitle" value="{{ old('linkedinTitle', $contact->linkedin_title) }}" placeholder="Executive Director" maxlength="160">
        </div>
    </div>
    <div class="form-actions"><button class="btn btn-primary" type="submit">Save contact details</button></div>
</form>
<p class="help">Contact details are public app information. Changes are recorded with field names only; contact values are not copied into business history.</p>
@endsection
