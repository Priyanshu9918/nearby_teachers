@php
    $headerCountry = $headerCountry ?? null;
    $headerLanguage = $language ?? 'en';
    $headerLanguages = $headerCountry
        ? config('seo.countries.'.$headerCountry.'.languages', ['en'])
        : array_keys(config('seo.language_names', ['en' => 'English']));
    $headerCopy = [
        'en' => ['cities' => 'Cities & villages', 'become' => 'Become a teacher', 'get_app' => 'Get the app'],
        'hi' => ['cities' => 'शहर और क्षेत्र', 'become' => 'शिक्षक बनें', 'get_app' => 'ऐप डाउनलोड करें'],
    ][$headerLanguage] ?? ['cities' => 'Cities & villages', 'become' => 'Become a teacher', 'get_app' => 'Get the app'];
    $headerDownload = isset($providerSlug)
        ? route('download', ['type' => $providerType ?? 'teacher', 'slug' => $providerSlug])
        : route('download');
@endphp
<header class="teacher-topbar directory-topbar">
    <div>
        <a class="teacher-brand" href="{{ \App\Services\SeoDirectory::url('/directory') }}">
            Nearby Teacher <small>by British School of Languages</small>
        </a>
        <nav aria-label="Main navigation">
            <a href="{{ \App\Services\SeoDirectory::url('/directory') }}#subjects">{{ $labels['subjects'] ?? 'Subjects taught' }}</a>
            <a href="{{ \App\Services\SeoDirectory::url('/directory') }}">{{ $headerCopy['cities'] }}</a>
            <a href="{{ route('download', ['type' => 'teacher']) }}">{{ $headerCopy['become'] }}</a>
        </nav>
        <form method="get" action="{{ request()->url() }}" class="directory-language">
            @foreach(request()->except(['lang', 'page']) as $key => $value)
                @if(is_scalar($value))<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endif
            @endforeach
            <label class="sr-only" for="seo-language">{{ $labels['language'] ?? 'Language' }}</label>
            <select id="seo-language" name="lang" onchange="this.form.submit()" aria-label="{{ $labels['language'] ?? 'Language' }}">
                @foreach($headerLanguages as $code)
                    <option value="{{ $code }}" @selected($headerLanguage === $code)>{{ config('seo.language_names.'.$code, strtoupper($code)) }}</option>
                @endforeach
            </select>
        </form>
        <a class="directory-app-link" href="{{ $headerDownload }}">{{ $headerCopy['get_app'] }}</a>
    </div>
</header>
