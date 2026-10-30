@extends('admin.layout')
@section('title', 'SEO subject wording')
@section('content')
<div class="page-heading"><div><p class="eyebrow">SEO settings</p><h1>Subject names and URL slugs</h1><p>Public URLs follow one format: <code>/country/location/subject</code>. Language is selected on the page and does not add a URL segment.</p></div></div>
<div class="panel panel-body"><p><a class="btn" href="{{ route('admin.reports.index', ['report' => 'seo-pages']) }}">SEO pages &amp; status</a> <a class="btn" href="{{ route('admin.reports.index', ['report' => 'seo-subject-routes']) }}">All subject mappings</a> <a class="btn" href="{{ route('admin.seo.edit') }}">New mapping</a></p><form method="post" action="{{ route('admin.seo.sync') }}">@csrf<button class="btn" type="submit">Sync existing teacher and institute pages</button></form></div>
<section class="panel panel-body"><form method="post" action="{{ route('admin.seo.save') }}">@csrf
<input type="hidden" name="id" value="{{ $editing->id ?? '' }}">
<div class="field"><label for="seo-subject">Subject</label><select name="subject_id" id="seo-subject" required>@foreach($subjects as $subject)<option value="{{ $subject->id }}" @selected(old('subject_id', $editing->subject_id ?? null) == $subject->id)>{{ $subject->name }}</option>@endforeach</select></div>
<div class="field"><label for="seo-country">Country</label><select name="country" id="seo-country" required>@foreach($countries as $code => $country)<option value="{{ $code }}" @selected(old('country', $editing->country ?? 'in') === $code)>{{ strtoupper($code) }} — {{ $country['name'] }}</option>@endforeach</select></div>
<div class="field"><label for="seo-language">Page language</label><select name="language" id="seo-language" required>@foreach($countries as $countryCode => $countrySettings)@foreach($countrySettings['languages'] as $languageCode)<option value="{{ $languageCode }}" data-country="{{ $countryCode }}" @selected(old('language', $editing->language ?? 'en') === $languageCode && old('country', $editing->country ?? 'in') === $countryCode)>{{ config('seo.language_names.'.$languageCode, strtoupper($languageCode)) }}</option>@endforeach @endforeach</select><small>Controls translated page text. It is not added to the URL.</small></div>
<div class="field"><label for="seo-display">Subject name in this language</label><input name="display_name" id="seo-display" required maxlength="200" value="{{ old('display_name', $editing->display_name ?? '') }}"></div>
<div class="field"><label for="seo-city-slug">Subject slug for city pages</label><input name="city_slug" id="seo-city-slug" required maxlength="230" placeholder="maths-tuition" value="{{ old('city_slug', $editing->city_slug ?? '') }}"></div>
<div class="field"><label for="seo-online-slug">Subject slug for online pages</label><input name="online_slug" id="seo-online-slug" required maxlength="230" placeholder="ielts-coaching" value="{{ old('online_slug', $editing->online_slug ?? '') }}"></div>
<div class="field"><label for="seo-enabled">Publication</label><select name="enabled" id="seo-enabled"><option value="1" @selected(old('enabled', $editing->enabled ?? 1) == 1)>Enabled</option><option value="0" @selected(old('enabled', $editing->enabled ?? 1) == 0)>Disabled</option></select></div>
<div class="field"><label>URL examples</label><p>City: <code id="seo-city-preview">/{{ strtolower($editing->country ?? 'in') }}/kanpur/{{ $editing->city_slug ?? 'maths-tuition' }}</code><br>Online: <code id="seo-online-preview">/{{ strtolower($editing->country ?? 'in') }}/online/{{ $editing->online_slug ?? 'ielts-coaching' }}</code></p></div>
<button class="btn" type="submit">Save subject settings</button></form></section>
<script>
(() => {
    const country = document.getElementById('seo-country');
    const citySlug = document.getElementById('seo-city-slug');
    const onlineSlug = document.getElementById('seo-online-slug');
    const cityPreview = document.getElementById('seo-city-preview');
    const onlinePreview = document.getElementById('seo-online-preview');
    const update = () => {
        cityPreview.textContent = `/${country.value}/kanpur/${citySlug.value || 'subject-slug'}`;
        onlinePreview.textContent = `/${country.value}/online/${onlineSlug.value || 'subject-slug'}`;
    };
    const language = document.getElementById('seo-language');
    const syncLanguages = () => {
        let selectedVisible = false;
        Array.from(language.options).forEach((option) => {
            option.hidden = option.dataset.country !== country.value;
            if (option.value === language.value && !option.hidden) selectedVisible = true;
        });
        if (!selectedVisible) language.value = language.querySelector(`option[data-country="${country.value}"]`).value;
    };
    [country, citySlug, onlineSlug].forEach((field) => field.addEventListener('input', update));
    country.addEventListener('change', syncLanguages);
    syncLanguages();
    update();
})();
</script>
@endsection
