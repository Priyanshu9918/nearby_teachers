@php
    $headerCountry = $headerCountry ?? null;
    $headerLanguage = $language ?? 'en';
    $headerCopy = ['cities' => $labels['cities'], 'become' => $labels['become'], 'get_app' => $labels['get_app']];
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
        @include('site.partials.language-picker', ['compact' => true, 'pickerId' => 'seo-language'])
        <a class="directory-app-link" href="{{ $headerDownload }}">{{ $headerCopy['get_app'] }}</a>
    </div>
</header>
