@php
    $compact = $compact ?? false;
    $pickerId = $pickerId ?? 'site-language';
@endphp
<form method="get" action="{{ request()->url() }}" class="{{ $compact ? 'directory-language' : 'lang-picker site-language-picker' }}" @unless($compact) style="max-width:760px;margin:24px auto;padding:16px 20px" @endunless>
    @foreach (request()->except(['lang', 'page']) as $key => $value)
        @if (is_scalar($value))<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endif
    @endforeach
    <label for="{{ $pickerId }}" @if($compact) class="sr-only" @endif>{{ data_get($t ?? [], 'settings.language', $labels['language'] ?? 'Language') }}</label>
    <select id="{{ $pickerId }}" name="lang" onchange="this.form.submit()">
        @foreach (\App\Support\PublicLanguages::all() as $option)
            <option value="{{ $option['code'] }}" @selected(($language ?? $lang) === $option['code'])>{{ $option['nativeName'] }}</option>
        @endforeach
    </select>
</form>
