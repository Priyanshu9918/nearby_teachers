@extends('admin.layout')
@section('title', 'SEO subject wording')
@section('content')
<div class="page-heading"><div><p class="eyebrow">International SEO</p><h1>Subject wording &amp; URLs</h1><p>Configure reviewed subject names and country/language slugs. Only listings with eligible teachers are published.</p></div></div>
<div class="panel panel-body"><p><a class="btn" href="{{ route('admin.reports.index', ['report' => 'seo-pages']) }}">SEO pages &amp; status</a> <a class="btn" href="{{ route('admin.reports.index', ['report' => 'seo-subject-routes']) }}">All subject mappings</a> <a class="btn" href="{{ route('admin.seo.edit') }}">New mapping</a></p><form method="post" action="{{ route('admin.seo.sync') }}">@csrf<button class="btn" type="submit">Sync existing teacher pages</button></form></div>
<section class="panel panel-body"><form method="post" action="{{ route('admin.seo.save') }}">@csrf
<input type="hidden" name="id" value="{{ $editing->id ?? '' }}">
<div class="field"><label for="seo-subject">Subject</label><select name="subject_id" id="seo-subject" required>@foreach($subjects as $subject)<option value="{{ $subject->id }}" @selected(old('subject_id', $editing->subject_id ?? null) == $subject->id)>{{ $subject->name }}</option>@endforeach</select></div>
<div class="field"><label for="seo-country">Country</label><select name="country" id="seo-country" required>@foreach($countries as $code => $country)<option value="{{ $code }}" @selected(old('country', $editing->country ?? 'in') === $code)>{{ strtoupper($code) }} — {{ $country['name'] }}</option>@endforeach</select></div>
<div class="field"><label for="seo-language">Language code (configured for the country)</label><input name="language" id="seo-language" required value="{{ old('language', $editing->language ?? 'en') }}"></div>
<div class="field"><label for="seo-display">Subject name in this language</label><input name="display_name" id="seo-display" required maxlength="200" value="{{ old('display_name', $editing->display_name ?? '') }}"></div>
<div class="field"><label for="seo-city-slug">Subject + city slug</label><input name="city_slug" id="seo-city-slug" required maxlength="230" placeholder="maths-tuition" value="{{ old('city_slug', $editing->city_slug ?? '') }}"></div>
<div class="field"><label for="seo-online-slug">Online subject slug</label><input name="online_slug" id="seo-online-slug" required maxlength="230" placeholder="ielts-coaching" value="{{ old('online_slug', $editing->online_slug ?? '') }}"></div>
<div class="field"><label for="seo-enabled">Publication</label><select name="enabled" id="seo-enabled"><option value="1" @selected(old('enabled', $editing->enabled ?? 1) == 1)>Enabled</option><option value="0" @selected(old('enabled', $editing->enabled ?? 1) == 0)>Disabled</option></select></div>
<button class="btn" type="submit">Save SEO wording</button></form></section>
@endsection
